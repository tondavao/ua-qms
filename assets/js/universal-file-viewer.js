/**
 * UA QMS Universal File Viewer Component
 * Fixed Preview Transparency Issue
 */

class UniversalFileViewer {
    constructor() {
        this.modal = null;
        this.contentArea = null;
        this.titleArea = null;
        this.initModal();
    }

    initModal() {
        let modal = document.getElementById("universal-file-viewer-modal");

        if (!modal) {
            const modalHtml = `
                <div id="universal-file-viewer-modal"
                     class="fixed inset-0 bg-black/70 z-[9999] hidden flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">

                    <div id="viewer-container"
                         class="bg-white rounded-2xl shadow-2xl w-full max-w-6xl h-[95vh] overflow-hidden flex flex-col transform scale-95 transition-transform duration-300">

                        <!-- Header -->
                        <div class="flex items-center justify-between px-6 py-4 border-b bg-[#800000] text-white">

                            <h3 id="universal-file-viewer-title"
                                class="font-bold text-white truncate">
                                Document Preview
                            </h3>

                            <div class="flex items-center gap-2">

                                <button id="universal-file-viewer-close"
                                        class="px-3 py-2 rounded-lg bg-red-800 text-white hover:bg-red-900 transition flex items-center gap-2 text-sm font-semibold border border-red-700">
                                    <i class="fas fa-times"></i> <span class="hidden sm:inline">Close</span>
                                </button>

                            </div>
                        </div>

                        <!-- Content -->
                        <div id="universal-file-viewer-content"
                             class="flex-1 bg-gray-200 overflow-auto flex items-center justify-center">

                            <div class="text-center">
                                <i class="fas fa-spinner fa-spin text-4xl text-gray-600 mb-3"></i>
                                <p class="text-gray-700">Loading preview...</p>
                            </div>

                        </div>
                    </div>
                </div>
            `;

            document.body.insertAdjacentHTML("beforeend", modalHtml);
            modal = document.getElementById("universal-file-viewer-modal");
        }

        this.modal = modal;
        this.contentArea = document.getElementById("universal-file-viewer-content");
        this.titleArea = document.getElementById("universal-file-viewer-title");

        document
            .getElementById("universal-file-viewer-close")
            .addEventListener("click", () => this.closeViewer());

        this.modal.addEventListener("click", (e) => {
            if (e.target === this.modal) {
                this.closeViewer();
            }
        });

        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape") {
                this.closeViewer();
            }
        });
    }

    openViewer(originalFilePath, previewFilePath, fileName) {

        if (!originalFilePath || originalFilePath === "undefined") {
            this.showError("File path is missing.");
            return;
        }

        // Normalize backslashes to forward slashes for URL compatibility
        originalFilePath = originalFilePath.replace(/\\/g, '/');
        if (previewFilePath) {
            previewFilePath = previewFilePath.replace(/\\/g, '/');
        }

        this.modal.classList.remove("hidden");
        // Trigger reflow for transition
        void this.modal.offsetWidth;
        this.modal.classList.remove("opacity-0");
        this.modal.classList.add("opacity-100");
        document.getElementById("viewer-container").classList.remove("scale-95");
        document.getElementById("viewer-container").classList.add("scale-100");

        this.titleArea.textContent = fileName;

        let previewPath = previewFilePath || originalFilePath;

        let extension = previewPath.split('.').pop().toLowerCase();

        if (extension === "pdf") {
            this.renderPdf(previewPath);

        } else if (["jpg","jpeg","png","gif","webp"].includes(extension)) {
            this.renderImage(previewPath);

        } else if (["mp4","webm","ogg"].includes(extension)) {
            this.renderVideo(previewPath);

        } else if (["mp3","wav"].includes(extension)) {
            this.renderAudio(previewPath);

        } else if (["txt","json","html","css","js","php","xml","md"].includes(extension)) {
            this.renderText(previewPath);

        } else {
            this.showError("Preview unavailable for this file type.");
        }
    }

    renderPdf(url) {
        this.contentArea.innerHTML = `
            <iframe
                src="${url}"
                class="w-full h-full bg-white"
                frameborder="0">
            </iframe>
        `;
    }

    renderImage(url) {
        this.contentArea.innerHTML = `
            <img
                src="${url}"
                class="max-w-full max-h-full object-contain"
                alt="Preview">
        `;
    }

    renderVideo(url) {
        this.contentArea.innerHTML = `
            <video controls class="w-full h-full bg-black">
                <source src="${url}">
            </video>
        `;
    }

    renderAudio(url) {
        this.contentArea.innerHTML = `
            <audio controls class="w-full max-w-xl">
                <source src="${url}">
            </audio>
        `;
    }

    renderText(url) {
        fetch(url)
            .then(response => response.text())
            .then(text => {
                this.contentArea.innerHTML = `
                    <pre class="w-full h-full overflow-auto p-4 bg-gray-900 text-green-400 text-sm text-left">
${this.escapeHtml(text)}
                    </pre>
                `;
            })
            .catch(() => {
                this.showError("Unable to load text preview.");
            });
    }

    showError(message) {
        this.contentArea.innerHTML = `
            <div class="text-center p-10">
                <i class="fas fa-exclamation-circle text-red-500 text-5xl mb-4"></i>
                <p class="text-lg font-semibold text-gray-700">${message}</p>
            </div>
        `;
    }

    closeViewer() {
        this.modal.classList.remove("opacity-100");
        this.modal.classList.add("opacity-0");
        document.getElementById("viewer-container").classList.remove("scale-100");
        document.getElementById("viewer-container").classList.add("scale-95");
        
        setTimeout(() => {
            this.modal.classList.add("hidden");
            this.contentArea.innerHTML = `
                <div class="text-center">
                    <i class="fas fa-spinner fa-spin text-4xl text-gray-600 mb-3"></i>
                    <p class="text-gray-700">Loading preview...</p>
                </div>
            `;
        }, 300);
    }

    forceDownload(url, filename) {
        const cleanUrl = url.replace(/\\/g, '/');
        fetch(cleanUrl)
            .then(response => {
                if (!response.ok) throw new Error("Network response was not ok");
                return response.blob();
            })
            .then(blob => {
                const blobUrl = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = blobUrl;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                a.remove();
                window.URL.revokeObjectURL(blobUrl);
            })
            .catch(() => {
                const a = document.createElement('a');
                a.href = cleanUrl;
                a.download = filename;
                a.target = '_blank';
                document.body.appendChild(a);
                a.click();
                a.remove();
            });
    }

    escapeHtml(text) {
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");
    }
}

if (document.readyState === 'loading') {
    document.addEventListener("DOMContentLoaded", () => {
        window.universalFileViewer = new UniversalFileViewer();
    });
} else {
    window.universalFileViewer = new UniversalFileViewer();
}

function openUniversalFileViewer(originalFilePath, previewFilePath, fileName) {
    window.universalFileViewer.openViewer(
        originalFilePath,
        previewFilePath,
        fileName
    );
}