// Configuration for large files (5GB - 25GB) with PARALLEL chunk uploads
// OPTIMIZED FOR LOCAL LAN - Maximum throughput settings
const CHUNK_SIZE = 16 * 1024 * 1024; // 16MB chunks - optimal for LAN
const PARALLEL_CHUNKS = 12; // 12 parallel uploads - saturate gigabit LAN
const MAX_RETRIES = 15; // More retries for reliability
const RETRY_DELAY = 1000; // 1 second - faster retry on LAN
const HEARTBEAT_INTERVAL = 20000; // 20 seconds
const CONNECTION_TIMEOUT = 120000; // 2 minutes per chunk

// Global state
let activeUploaders = [];
let isPaused = false;
let isUploading = false;
let currentFileIndex = 0;
let filesToUpload = [];

class ChunkedUploader {
    constructor(file, index) {
        this.file = file;
        this.index = index;
        this.uploadId = `${Date.now()}-${Math.random().toString(36).slice(2, 11)}`;
        this.totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        this.completedChunks = new Set();
        this.activeUploads = new Map();
        this.aborted = false;
        this.paused = false;
        this.heartbeatTimer = null;
        this.lastActivity = Date.now();
        this.bytesUploaded = 0;
        this.startTime = null;
        this.pauseResolvers = []; // Array to hold all waiting resolvers
    }

    async upload() {
        this.startTime = Date.now();
        this.startHeartbeat();

        try {
            const initResponse = await this.initializeUpload();
            if (!initResponse.success) {
                throw new Error(initResponse.message || 'Failed to initialize upload');
            }

            // Resume support
            if (initResponse.uploadedChunks && initResponse.completedChunkList) {
                initResponse.completedChunkList.forEach(i => this.completedChunks.add(i));
                this.bytesUploaded = this.completedChunks.size * CHUNK_SIZE;
                this.updateProgress();
            }

            await this.uploadAllChunksParallel();

            if (this.aborted) {
                throw new Error('Upload aborted');
            }

            const finalResponse = await this.finalizeUpload();
            this.stopHeartbeat();
            return finalResponse;

        } catch (error) {
            this.stopHeartbeat();
            throw error;
        }
    }

    async uploadAllChunksParallel() {
        const pendingChunks = [];
        for (let i = 0; i < this.totalChunks; i++) {
            if (!this.completedChunks.has(i)) {
                pendingChunks.push(i);
            }
        }

        // Shared index for all workers
        const lock = { index: 0 };

        const uploadNext = async () => {
            while (!this.aborted) {
                // Wait if paused
                while (this.paused && !this.aborted) {
                    await this.waitForResume();
                }
                
                if (this.aborted) break;

                // Get next chunk index atomically
                const myIndex = lock.index++;
                if (myIndex >= pendingChunks.length) break;
                
                const chunkIndex = pendingChunks[myIndex];
                
                try {
                    await this.uploadChunkWithRetry(chunkIndex);
                    if (!this.aborted && !this.paused) {
                        this.completedChunks.add(chunkIndex);
                        this.updateProgress();
                    }
                } catch (error) {
                    if (this.aborted) break;
                    if (!this.paused) throw error;
                }
            }
        };

        // Start all parallel workers
        const workers = [];
        for (let i = 0; i < PARALLEL_CHUNKS; i++) {
            workers.push(uploadNext());
        }

        await Promise.all(workers);
    }

    waitForResume() {
        return new Promise(resolve => {
            this.pauseResolvers.push(resolve);
        });
    }

    pause() {
        this.paused = true;
        // Abort all active XHR requests
        this.activeUploads.forEach(xhr => xhr.abort());
        this.activeUploads.clear();
        this.updateStatus(`Paused - ${this.completedChunks.size}/${this.totalChunks} chunks saved`);
    }

    resume() {
        this.paused = false;
        // Resolve ALL waiting promises so all workers continue
        const resolvers = this.pauseResolvers.splice(0);
        resolvers.forEach(resolve => resolve());
    }

    async initializeUpload() {
        const response = await fetch('upload.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'init',
                uploadId: this.uploadId,
                fileName: this.file.name,
                fileSize: this.file.size,
                totalChunks: this.totalChunks,
                chunkSize: CHUNK_SIZE
            })
        });
        return response.json();
    }

    async uploadChunkWithRetry(chunkIndex) {
        let lastError;

        for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
            if (this.aborted) throw new Error('Upload aborted');
            if (this.paused) throw new Error('Upload paused');

            try {
                await this.uploadChunk(chunkIndex);
                return;
            } catch (error) {
                if (this.aborted || this.paused) throw error;
                lastError = error;
                if (attempt < MAX_RETRIES) {
                    await this.delay(RETRY_DELAY * (attempt + 1));
                }
            }
        }
        throw lastError;
    }

    uploadChunk(chunkIndex) {
        return new Promise((resolve, reject) => {
            if (this.paused || this.aborted) {
                reject(new Error(this.aborted ? 'Upload aborted' : 'Upload paused'));
                return;
            }

            const start = chunkIndex * CHUNK_SIZE;
            const end = Math.min(start + CHUNK_SIZE, this.file.size);
            const chunk = this.file.slice(start, end);
            const chunkSize = end - start;

            const formData = new FormData();
            formData.append('action', 'upload_chunk');
            formData.append('uploadId', this.uploadId);
            formData.append('chunkIndex', chunkIndex);
            formData.append('totalChunks', this.totalChunks);
            formData.append('chunk', chunk);

            const xhr = new XMLHttpRequest();
            this.activeUploads.set(chunkIndex, xhr);

            xhr.open('POST', 'upload.php', true);
            xhr.timeout = CONNECTION_TIMEOUT;

            xhr.upload.onprogress = () => {
                this.lastActivity = Date.now();
                if (!this.paused) this.updateProgressWithActiveChunks();
            };

            xhr.onload = () => {
                this.activeUploads.delete(chunkIndex);
                this.lastActivity = Date.now();

                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            this.bytesUploaded += chunkSize;
                            resolve(response);
                        } else {
                            reject(new Error(response.message || 'Chunk upload failed'));
                        }
                    } catch (e) {
                        reject(new Error('Invalid server response'));
                    }
                } else {
                    reject(new Error(`HTTP ${xhr.status}`));
                }
            };

            xhr.onerror = () => {
                this.activeUploads.delete(chunkIndex);
                reject(new Error('Network error'));
            };
            xhr.ontimeout = () => {
                this.activeUploads.delete(chunkIndex);
                reject(new Error('Request timeout'));
            };
            xhr.onabort = () => {
                this.activeUploads.delete(chunkIndex);
                // Don't reject with error if paused - just resolve quietly
                if (this.paused) {
                    reject(new Error('Upload paused'));
                } else {
                    reject(new Error('Upload aborted'));
                }
            };

            xhr.send(formData);
        });
    }

    async finalizeUpload() {
        const response = await fetch('upload.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'finalize',
                uploadId: this.uploadId,
                fileName: this.file.name,
                totalChunks: this.totalChunks
            })
        });
        return response.json();
    }

    startHeartbeat() {
        this.heartbeatTimer = setInterval(async () => {
            if (this.paused) return;
            try {
                await fetch('upload.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'heartbeat',
                        uploadId: this.uploadId
                    })
                });
            } catch (e) {
                console.warn('Heartbeat failed:', e);
            }
        }, HEARTBEAT_INTERVAL);
    }

    stopHeartbeat() {
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
    }

    abort() {
        this.aborted = true;
        this.paused = false;
        this.stopHeartbeat();
        this.activeUploads.forEach(xhr => xhr.abort());
        this.activeUploads.clear();
        // Release all paused workers
        const resolvers = this.pauseResolvers.splice(0);
        resolvers.forEach(resolve => resolve());
    }

    updateProgressWithActiveChunks() {
        const completedBytes = this.completedChunks.size * CHUNK_SIZE;
        const percent = Math.min((completedBytes / this.file.size) * 100, 100);
        this.updateProgressBar(percent);

        const elapsed = (Date.now() - this.startTime) / 1000;
        const speed = elapsed > 0 ? this.bytesUploaded / elapsed : 0;
        const remaining = speed > 0 ? (this.file.size - this.bytesUploaded) / speed : 0;

        this.updateStatus(
            `${this.completedChunks.size}/${this.totalChunks} chunks | ` +
            `${formatFileSize(speed)}/s | ` +
            `ETA: ${formatTime(remaining)} | ` +
            `${this.activeUploads.size} active`
        );
    }

    updateProgress() {
        this.updateProgressWithActiveChunks();
    }

    updateProgressBar(percent) {
        const progressBar = document.getElementById(`progress-${this.index + 1}`);
        if (progressBar) {
            progressBar.style.width = `${percent}%`;
            progressBar.textContent = `${percent.toFixed(1)}%`;
        }
    }

    updateStatus(text) {
        const statusEl = document.getElementById(`status-${this.index + 1}`);
        if (statusEl) statusEl.textContent = text;
    }

    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}


function formatFileSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function formatTime(seconds) {
    if (!isFinite(seconds) || seconds < 0) return '--:--';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);
    if (h > 0) return `${h}h ${m}m`;
    if (m > 0) return `${m}m ${s}s`;
    return `${s}s`;
}

async function uploadFiles() {
    const files = document.getElementById('fileInput').files;
    const status = document.getElementById('status');
    const fileTableBody = document.getElementById('fileTableBody');

    if (files.length === 0) {
        alert('Please select a file.');
        return;
    }

    // Reset state
    isPaused = false;
    isUploading = true;
    currentFileIndex = 0;
    filesToUpload = Array.from(files);
    activeUploaders = [];

    updateButtonStates('uploading');

    let completedUploads = 0;
    let failedUploads = 0;
    const totalFiles = files.length;

    // Build file table
    fileTableBody.innerHTML = '';
    for (let i = 0; i < files.length; i++) {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td data-label="#">${i + 1}</td>
            <td data-label="File" class="file-link-cell">
                <a id="file-link-${i + 1}" href="#" target="_blank">${files[i].name}</a>
                <br><small class="text-muted">${formatFileSize(files[i].size)} (${Math.ceil(files[i].size / CHUNK_SIZE)} chunks)</small>
            </td>
            <td data-label="Progress">
                <div class="progress" style="height: 24px;">
                    <div id="progress-${i + 1}" class="progress-bar progress-bar-striped progress-bar-animated" 
                         role="progressbar" style="width: 0%; line-height: 24px;" aria-valuenow="0">0%</div>
                </div>
            </td>
            <td data-label="Status" id="status-${i + 1}" class="status-cell">Queued</td>
        `;
        fileTableBody.appendChild(row);
    }

    status.textContent = `Uploading ${totalFiles} file(s) with ${PARALLEL_CHUNKS} parallel connections...`;

    // Upload files sequentially, chunks in parallel
    for (let i = 0; i < files.length; i++) {
        // Check if we should stop
        if (!isUploading) break;
        
        // Wait while paused before starting next file
        while (isPaused && isUploading) {
            await new Promise(r => setTimeout(r, 500));
        }
        if (!isUploading) break;

        currentFileIndex = i;
        const file = files[i];
        const uploader = new ChunkedUploader(file, i);
        activeUploaders[i] = uploader;

        try {
            const response = await uploader.upload();
            completedUploads++;

            const linkElement = document.getElementById(`file-link-${i + 1}`);
            const statusElement = document.getElementById(`status-${i + 1}`);
            const progressBar = document.getElementById(`progress-${i + 1}`);

            if (response.success && response.filePath) {
                linkElement.href = response.filePath;
                statusElement.textContent = 'Completed';
                statusElement.className = 'text-success';
                progressBar.classList.remove('progress-bar-animated', 'progress-bar-striped');
                progressBar.classList.add('bg-success');
                progressBar.style.width = '100%';
                progressBar.textContent = '100%';
            } else {
                throw new Error(response.message || 'Upload failed');
            }
        } catch (error) {
            const statusElement = document.getElementById(`status-${i + 1}`);
            const progressBar = document.getElementById(`progress-${i + 1}`);
            
            if (error.message === 'Upload aborted') {
                statusElement.textContent = `Aborted - ${uploader.completedChunks.size}/${uploader.totalChunks} chunks saved`;
                statusElement.className = 'text-warning';
                progressBar.classList.remove('progress-bar-animated', 'progress-bar-striped');
                progressBar.classList.add('bg-warning');
            } else {
                failedUploads++;
                statusElement.textContent = `Failed: ${error.message}`;
                statusElement.className = 'text-danger';
                progressBar.classList.remove('progress-bar-animated', 'progress-bar-striped');
                progressBar.classList.add('bg-danger');
            }
        }
    }

    isUploading = false;
    updateButtonStates('idle');

    // Update queued files status if cancelled
    for (let i = currentFileIndex + 1; i < filesToUpload.length; i++) {
        const statusElement = document.getElementById(`status-${i + 1}`);
        if (statusElement && statusElement.textContent === 'Queued') {
            statusElement.textContent = 'Cancelled';
            statusElement.className = 'text-muted';
        }
    }

    if (failedUploads === 0 && completedUploads === totalFiles) {
        status.textContent = `All ${totalFiles} file(s) uploaded successfully!`;
        status.className = 'mt-3 text-success';
    } else if (completedUploads < totalFiles) {
        status.textContent = `${completedUploads}/${totalFiles} completed. Chunks saved on server for resume.`;
        status.className = 'mt-3 text-warning';
    } else {
        status.textContent = `${completedUploads}/${totalFiles} succeeded, ${failedUploads} failed`;
        status.className = 'mt-3 text-warning';
    }
}

function pauseUploads() {
    isPaused = true;
    activeUploaders.forEach(uploader => {
        if (uploader && !uploader.aborted) uploader.pause();
    });
    document.getElementById('status').textContent = 'Uploads paused - chunks saved on server';
    updateButtonStates('paused');
}

function resumeUploads() {
    isPaused = false;
    activeUploaders.forEach(uploader => {
        if (uploader && !uploader.aborted) uploader.resume();
    });
    document.getElementById('status').textContent = `Resuming uploads with ${PARALLEL_CHUNKS} parallel connections...`;
    updateButtonStates('uploading');
}

function cancelUploads() {
    isUploading = false;
    isPaused = false;
    activeUploaders.forEach(uploader => {
        if (uploader) uploader.abort();
    });
    document.getElementById('status').textContent = 'Uploads cancelled - chunks remain saved on server';
    updateButtonStates('idle');
}

function updateButtonStates(state) {
    const uploadBtn = document.getElementById('uploadBtn');
    const pauseBtn = document.getElementById('pauseBtn');
    const resumeBtn = document.getElementById('resumeBtn');
    const cancelBtn = document.getElementById('cancelBtn');

    switch (state) {
        case 'idle':
            uploadBtn.disabled = false;
            pauseBtn.disabled = true;
            resumeBtn.disabled = true;
            cancelBtn.disabled = true;
            break;
        case 'uploading':
            uploadBtn.disabled = true;
            pauseBtn.disabled = false;
            resumeBtn.disabled = true;
            cancelBtn.disabled = false;
            break;
        case 'paused':
            uploadBtn.disabled = true;
            pauseBtn.disabled = true;
            resumeBtn.disabled = false;
            cancelBtn.disabled = false;
            break;
    }
}

// Initialize button states on page load
document.addEventListener('DOMContentLoaded', () => {
    updateButtonStates('idle');
    initDragAndDrop();
});

// ============================================
// DRAG AND DROP FUNCTIONALITY
// ============================================
function initDragAndDrop() {
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    
    if (!dropZone || !fileInput) return;
    
    // Prevent default drag behaviors
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
        document.body.addEventListener(eventName, preventDefaults, false);
    });
    
    // Highlight drop zone
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.add('drag-over'), false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.remove('drag-over'), false);
    });
    
    // Handle dropped files
    dropZone.addEventListener('drop', handleDrop, false);
    
    // Handle file input change
    fileInput.addEventListener('change', handleFileSelect, false);
}

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;
    document.getElementById('fileInput').files = files;
    handleFileSelect();
}

function handleFileSelect() {
    const files = document.getElementById('fileInput').files;
    if (files.length > 0) {
        const fileTableBody = document.getElementById('fileTableBody');
        fileTableBody.innerHTML = '';
        
        let totalSize = 0;
        for (let i = 0; i < files.length; i++) {
            totalSize += files[i].size;
            const row = document.createElement('tr');
            row.innerHTML = `
                <td data-label="#">${i + 1}</td>
                <td data-label="File" class="file-link-cell">
                    ${files[i].name}
                    <br><small class="text-muted">${formatFileSize(files[i].size)}</small>
                </td>
                <td data-label="Progress">
                    <div class="progress" style="height: 24px;">
                        <div class="progress-bar bg-secondary" style="width: 100%; line-height: 24px;">Ready</div>
                    </div>
                </td>
                <td data-label="Status" class="status-cell">Ready</td>
            `;
            fileTableBody.appendChild(row);
        }
        
        document.getElementById('status').textContent = 
            `${files.length} file(s) selected (${formatFileSize(totalSize)} total). Click Upload to start.`;
        document.getElementById('status').className = 'mt-3 text-info';
    }
}

// File browser functions are in file-browser.js
