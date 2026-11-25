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

        } else if (extension === "docx") {
            this.renderDocx(previewPath, fileName);

        } else if (["doc","xls","xlsx","ppt","pptx"].includes(extension)) {
            this.renderOfficeDownload(originalFilePath, fileName, extension);

        } else if (["mp4","webm","ogg"].includes(extension)) {
            this.renderVideo(previewPath);

        } else if (["mp3","wav"].includes(extension)) {
            this.renderAudio(previewPath);

        } else if (["txt","json","html","css","js","php","xml","md"].includes(extension)) {
            this.renderText(previewPath);

        } else {
            this.renderOfficeDownload(originalFilePath, fileName, extension);
        }
    }

    ensureMammoth() {
        if (typeof window.mammoth !== "undefined") return Promise.resolve(window.mammoth);
        return new Promise((resolve, reject) => {
            const s = document.createElement("script");
            s.src = "https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.8.0/mammoth.browser.min.js";
            s.onload = () => resolve(window.mammoth);
            s.onerror = () => reject(new Error("Could not load DOCX preview engine"));
            document.head.appendChild(s);
        });
    }

    renderDocx(url, fileName) {
        this.contentArea.innerHTML = `
            <div class="text-center">
                <i class="fas fa-spinner fa-spin text-4xl text-[#800000] mb-3"></i>
                <p class="text-gray-700 font-medium">Rendering Word document...</p>
            </div>
        `;
        this.ensureMammoth()
            .then(mammoth => fetch(url + (url.includes('?') ? '&' : '?') + '_t=' + Date.now())
                .then(res => {
                    if (!res.ok) throw new Error("Failed to load file");
                    return res.arrayBuffer();
                })
                .then(buf => mammoth.convertToHtml({ arrayBuffer: buf }))
            )
            .then(result => {
                this.contentArea.innerHTML = `
                    <div class="w-full h-full overflow-auto p-4 md:p-8 bg-gray-200 text-left">
                        <div class="max-w-4xl mx-auto bg-white rounded-lg shadow-lg p-6 md:p-10 text-gray-900 text-sm leading-relaxed">
                            <div class="flex justify-end pb-3 mb-4 border-b border-gray-200">
                                <a href="${url}" download="${this.escapeHtml(fileName)}" class="px-3.5 py-1.5 bg-[#800000] hover:bg-red-900 text-white rounded-lg text-xs font-bold inline-flex items-center gap-1.5 transition">
                                    <i class="fas fa-download"></i> Download DOCX
                                </a>
                            </div>
                            <div class="space-y-3 [&_table]:w-full [&_table]:border-collapse [&_td]:border [&_td]:border-gray-400 [&_td]:p-2 [&_th]:border [&_th]:border-gray-400 [&_th]:p-2 [&_th]:bg-gray-100 [&_h1]:text-xl [&_h1]:font-bold [&_h2]:text-lg [&_h2]:font-bold [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5">
                                ${result.value || '<p class="text-gray-400 italic">(Document is empty)</p>'}
                            </div>
                        </div>
                    </div>
                `;
            })
            .catch(() => {
                this.renderOfficeDownload(url, fileName, "docx");
            });
    }

    renderOfficeDownload(url, fileName, ext) {
        this.contentArea.innerHTML = `
            <div class="text-center p-10 bg-white rounded-xl shadow max-w-md mx-4">
                <i class="fas fa-file-alt text-[#800000] text-5xl mb-4"></i>
                <p class="text-base font-bold text-gray-800 mb-1">${this.escapeHtml(fileName)}</p>
                <p class="text-xs text-gray-500 mb-5">Inline preview is not available for .${this.escapeHtml(ext)} files. Download to open locally.</p>
                <a href="${url}" download="${this.escapeHtml(fileName)}" class="px-4 py-2 bg-[#800000] hover:bg-red-900 text-white rounded-lg text-xs font-bold inline-flex items-center gap-2 transition">
                    <i class="fas fa-download"></i> Download File
                </a>
            </div>
        `;
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