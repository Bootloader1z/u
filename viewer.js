/**
 * File Viewer Module
 * Handles streaming, image viewing, and PDF display
 */

const FileViewer = {
    files: [],
    currentFilter: 'all',

    // File type mappings
    types: {
        image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'],
        video: ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv', 'm4v'],
        audio: ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma'],
        pdf: ['pdf']
    },

    icons: {
        image: '🖼️',
        video: '🎬',
        audio: '🎵',
        pdf: '📄',
        other: '📦'
    },

    init() {
        this.bindFilterEvents();
        this.loadFiles();
    },

    bindFilterEvents() {
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                e.target.classList.add('active');
                this.currentFilter = e.target.dataset.filter;
                this.renderFiles();
            });
        });
    },

    getFileType(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        for (const [type, exts] of Object.entries(this.types)) {
            if (exts.includes(ext)) return type;
        }
        return 'other';
    },

    getIcon(type) {
        return this.icons[type] || this.icons.other;
    },

    formatSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },

    async loadFiles(retryCount = 0) {
        const fileList = document.getElementById('fileList');
        fileList.innerHTML = '<div class="loading-state">Loading files...</div>';

        const maxRetries = 3;
        const timeout = 30000; // 30 seconds

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), timeout);

            const response = await fetch('upload.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'list' }),
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            const data = await response.json();

            if (data.success && data.files) {
                this.files = data.files.map(f => ({
                    ...f,
                    type: this.getFileType(f.name)
                }));
                this.renderFiles();
            } else {
                fileList.innerHTML = '<div class="empty-state">📂 No files found</div>';
            }
        } catch (error) {
            // Retry on connection errors
            if (retryCount < maxRetries && (error.name === 'AbortError' || error.message.includes('network') || error.message.includes('Failed to fetch'))) {
                fileList.innerHTML = `<div class="loading-state">Retrying... (${retryCount + 1}/${maxRetries})</div>`;
                await new Promise(r => setTimeout(r, 1000 * (retryCount + 1)));
                return this.loadFiles(retryCount + 1);
            }
            fileList.innerHTML = `<div class="empty-state" style="color:#dc3545;">❌ Error: ${error.message}<br><button onclick="FileViewer.loadFiles()" class="btn btn-sm btn-outline-primary mt-2">Try Again</button></div>`;
        }
    },

    renderFiles() {
        const fileList = document.getElementById('fileList');
        let filtered = this.files;

        if (this.currentFilter !== 'all') {
            filtered = this.files.filter(f => f.type === this.currentFilter);
        }

        if (filtered.length === 0) {
            fileList.innerHTML = '<div class="empty-state">No files match this filter</div>';
            return;
        }

        fileList.innerHTML = filtered.map((file, idx) => `
            <div class="file-item" data-index="${this.files.indexOf(file)}">
                <span class="file-icon">${this.getIcon(file.type)}</span>
                <div class="file-info">
                    <div class="file-name">${this.escapeHtml(file.name)}</div>
                    <div class="file-meta">${this.formatSize(file.size)} • ${file.date}</div>
                </div>
                <div class="file-actions">
                    ${this.canPreview(file.type) ? 
                        `<button class="btn btn-outline-primary" onclick="FileViewer.openViewer(${this.files.indexOf(file)}); event.stopPropagation();">View</button>` : ''}
                    <a href="${this.getDownloadUrl(file.path)}" download="${file.name}" class="btn btn-outline-secondary" onclick="event.stopPropagation();">⬇️</a>
                </div>
            </div>
        `).join('');

        // Click on row to preview
        fileList.querySelectorAll('.file-item').forEach(item => {
            item.addEventListener('click', () => {
                const idx = parseInt(item.dataset.index);
                if (this.canPreview(this.files[idx].type)) {
                    this.openViewer(idx);
                }
            });
        });
    },

    canPreview(type) {
        return ['image', 'video', 'audio', 'pdf'].includes(type);
    },

    getDownloadUrl(path) {
        return `upload.php?action=download&file=${encodeURIComponent(path)}`;
    },

    getStreamUrl(path) {
        return `stream.php?file=${encodeURIComponent(path)}`;
    },

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    openViewer(index) {
        const file = this.files[index];
        if (!file) return;

        document.getElementById('fileListSection').style.display = 'none';
        document.getElementById('viewerSection').style.display = 'block';
        document.getElementById('viewerFileName').textContent = file.name;
        document.getElementById('downloadBtn').href = this.getDownloadUrl(file.path);
        document.getElementById('downloadBtn').download = file.name;

        const content = document.getElementById('viewerContent');
        const streamUrl = this.getStreamUrl(file.path);

        switch (file.type) {
            case 'video':
                content.innerHTML = `
                    <video controls autoplay playsinline>
                        <source src="${streamUrl}" type="video/${this.getVideoMime(file.name)}">
                        Your browser doesn't support video playback.
                    </video>`;
                break;

            case 'audio':
                content.innerHTML = `
                    <audio controls autoplay>
                        <source src="${streamUrl}" type="audio/${this.getAudioMime(file.name)}">
                        Your browser doesn't support audio playback.
                    </audio>`;
                break;

            case 'image':
                content.innerHTML = `<img src="${streamUrl}" alt="${this.escapeHtml(file.name)}" loading="lazy">`;
                break;

            case 'pdf':
                content.innerHTML = `<iframe src="${streamUrl}#toolbar=1&navpanes=0" title="PDF Viewer"></iframe>`;
                break;

            default:
                content.innerHTML = `
                    <div class="unsupported">
                        <p>Preview not available for this file type.</p>
                        <a href="${this.getDownloadUrl(file.path)}" download>Download file</a>
                    </div>`;
        }
    },

    getVideoMime(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        const mimes = { mp4: 'mp4', webm: 'webm', ogg: 'ogg', mov: 'quicktime' };
        return mimes[ext] || 'mp4';
    },

    getAudioMime(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        const mimes = { mp3: 'mpeg', wav: 'wav', ogg: 'ogg', flac: 'flac', aac: 'aac', m4a: 'mp4' };
        return mimes[ext] || 'mpeg';
    },

    closeViewer() {
        // Stop any playing media
        const video = document.querySelector('#viewerContent video');
        const audio = document.querySelector('#viewerContent audio');
        if (video) video.pause();
        if (audio) audio.pause();

        document.getElementById('viewerSection').style.display = 'none';
        document.getElementById('fileListSection').style.display = 'block';
        document.getElementById('viewerContent').innerHTML = '';
    }
};

// Initialize on load
document.addEventListener('DOMContentLoaded', () => FileViewer.init());
