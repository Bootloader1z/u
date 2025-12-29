/**
 * File Browser Module - Lightweight file listing
 * Separated for better performance
 */

const FileBrowser = {
    modal: null,
    body: null,
    isOpen: false,

    init() {
        this.modal = document.getElementById('fileBrowserModal');
        this.body = document.getElementById('fileBrowserBody');
        this.bindEvents();
    },

    bindEvents() {
        // Close on overlay click
        if (this.modal) {
            this.modal.addEventListener('click', (e) => {
                if (e.target === this.modal) {
                    this.close();
                }
            });
        }

        // Close on Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen) {
                this.close();
            }
        });
    },

    open() {
        if (!this.modal) this.init();
        this.modal.style.display = 'flex';
        this.isOpen = true;
        document.body.style.overflow = 'hidden';
        this.loadFiles();
    },

    close() {
        if (this.modal) {
            this.modal.style.display = 'none';
            this.isOpen = false;
            document.body.style.overflow = '';
        }
    },

    async loadFiles() {
        this.body.innerHTML = '<div class="fb-loading">Loading files...</div>';

        try {
            const response = await fetch('upload.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'list' })
            });

            const data = await response.json();

            if (data.success && data.files && data.files.length > 0) {
                this.renderFiles(data.files);
            } else {
                this.body.innerHTML = '<div class="fb-loading">📂 No files uploaded yet.</div>';
            }
        } catch (error) {
            this.body.innerHTML = `<div class="fb-loading" style="color:#dc3545;">❌ Error: ${error.message}</div>`;
        }
    },

    renderFiles(files) {
        let html = '<div class="file-list-container">';
        
        files.forEach(file => {
            html += `<div class="file-item">
                <a href="${file.path}" target="_blank">${file.name}</a>
                <span class="file-meta">${this.formatSize(file.size)} • ${file.date}</span>
            </div>`;
        });
        
        html += '</div>';
        this.body.innerHTML = html;
    },

    formatSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
};

// Global functions
function loadFileList() {
    FileBrowser.open();
}

function closeFileBrowser() {
    FileBrowser.close();
}

// Initialize
document.addEventListener('DOMContentLoaded', () => FileBrowser.init());
