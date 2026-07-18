/**
 * UA QMS File Viewer Component - FIXED VERSION
 * Enhanced inline preview for PDF, Images, and Office Documents
 */

// Initialize PDF.js worker
if (typeof pdfjsLib !== 'undefined') {
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}

function openFileViewer(filePath, fileName) {
    console.log("Opening file viewer for:", fileName, "at path:", filePath);
    
    // Create modal if it doesn't exist
    let modal = document.getElementById('file-viewer-modal');
    if (!modal) {
        createFileViewerModal();
        modal = document.getElementById('file-viewer-modal');
    }

    const content = document.getElementById('file-viewer-content');
    const title = document.getElementById('file-viewer-title');
    
    // Clear previous content
    content.innerHTML = '<div class="flex flex-col items-center justify-center w-full h-full text-white"><i class="fas fa-spinner fa-spin fa-2x mb-3"></i> Loading preview...</div>';
    title.textContent = fileName;
    
    // Normalize file path
    let cleanPath = normalizePath(filePath);
    
    // Determine file type
    const fileExt = fileName.split('.').pop().toLowerCase();
    console.log("Detected extension:", fileExt);
    
    setTimeout(() => {
        content.innerHTML = '';
        
        if (['pdf'].includes(fileExt)) {
            loadPdfViewer(content, cleanPath, fileName);
        } else if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(fileExt)) {
            loadImageViewer(content, cleanPath, fileName);
        } else if (['docx', 'xlsx', 'pptx', 'doc', 'xls', 'ppt'].includes(fileExt)) {
            // Office documents - Use Google Docs Viewer with absolute URL
            loadOfficeViewer(content, cleanPath, fileName);
        } else {
            showPreviewError(content, cleanPath, fileName, fileExt);
        }
    }, 300);
    
    // Show modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

/**
 * Normalize file path for cross-page compatibility
 */
function normalizePath(filePath) {
    if (!filePath) return '';
    
    // Replace all backslashes with forward slashes for URL compatibility
    let cleanPath = filePath.replace(/\\/g, '/');
    
    // If it's already an absolute URL, return it
    if (cleanPath.startsWith('http://') || cleanPath.startsWith('https://')) {
        return cleanPath;
    }
    
    let cleanPathFinal = cleanPath;
    const currentPath = window.location.pathname;
    const isInDashboard = currentPath.includes('/dashboard/');
    
    if (isInDashboard) {
        if (!cleanPathFinal.startsWith('../')) {
            cleanPathFinal = '../../' + cleanPathFinal.replace(/^\/+/, '');
        }
    }
    
    return cleanPathFinal;
}

/**
 * Load PDF using PDF.js or fallback to iframe
 */
function loadPdfViewer(container, filePath, fileName) {
    // Fallback to iframe is often more reliable for simple viewing
    // as it uses the browser's native PDF viewer
    const iframe = document.createElement('iframe');
    iframe.src = filePath;
    iframe.style.width = '100%';
    iframe.style.height = '100%';
    iframe.style.border = 'none';
    iframe.className = 'bg-white';
    iframe.onerror = function() {
        container.innerHTML = '<div class="file-viewer-error"><i class="fas fa-exclamation-triangle"></i><h4>PDF Preview Unavailable</h4><p>The PDF could not be loaded. Please download to view.</p></div>';
    };
    container.appendChild(iframe);
}

/**
 * Load image viewer
 */
function loadImageViewer(container, filePath, fileName) {
    const img = document.createElement('img');
    img.src = filePath;
    img.alt = fileName;
    img.className = 'max-w-full max-h-full object-contain shadow-2xl';
    img.onerror = function() {
        showPreviewError(container, filePath, fileName, 'image');
    };
    container.appendChild(img);
}

/**
 * Load Office document using Google Docs Viewer
 */
function loadOfficeViewer(container, filePath, fileName) {
    // Construct absolute URL for Google Viewer
    // Google Viewer MUST have a public absolute URL
    let absoluteUrl = getAbsoluteUrl(filePath);
    
    console.log("Absolute URL for Google Viewer:", absoluteUrl);
    
    // Check if we are on localhost - Google Viewer won't work on localhost
    if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
        container.innerHTML = `
            <div class="file-viewer-error">
                <i class="fas fa-laptop-code"></i>
                <h4 class="text-lg font-bold mb-2">Local Preview Restricted</h4>
                <p class="mb-4">Office document previews (DOCX, XLSX) require a public URL for Google Docs Viewer. This feature will work once deployed to a live server.</p>
                <a href="${filePath}" download="${fileName}" class="file-viewer-download-btn">
                    <i class="fas fa-download"></i> Download to View Locally
                </a>
            </div>
        `;
        return;
    }
    
    const iframe = document.createElement('iframe');
    // Use both Google and Microsoft viewers as fallbacks if needed
    iframe.src = 'https://docs.google.com/gview?embedded=true&url=' + encodeURIComponent(absoluteUrl);
    iframe.style.width = '100%';
    iframe.style.height = '100%';
    iframe.style.border = 'none';
    iframe.className = 'bg-white';
    container.appendChild(iframe);
    
    const note = document.createElement('div');
    note.className = 'preview-note';
    note.innerHTML = 'Preview powered by Google Docs Viewer';
    container.appendChild(note);
}

/**
 * Convert relative path to absolute URL
 */
function getAbsoluteUrl(filePath) {
    if (filePath.startsWith('http://') || filePath.startsWith('https://')) {
        return filePath;
    }
    
    // Create a temporary anchor element to resolve the relative path
    const a = document.createElement('a');
    a.href = filePath;
    return a.href;
}

/**
 * Show preview error with helpful guidance
 */
function showPreviewError(container, path, name, ext) {
    container.innerHTML = `
        <div class="file-viewer-error">
            <i class="fas fa-file-alt"></i>
            <h4 class="text-lg font-bold mb-2">Preview Unavailable</h4>
            <p class="mb-4">We can't preview this file type (${ext.toUpperCase()}) directly in the browser.</p>
            <div class="flex flex-col gap-3 items-center">
                <a href="${path}" download="${name}" class="file-viewer-download-btn">
                    <i class="fas fa-download"></i> Download to View
                </a>
                <p class="text-xs text-gray-500 mt-2">Supported formats: PDF, JPG, PNG, GIF, DOCX, XLSX, PPTX</p>
            </div>
        </div>
    `;
}

/**
 * Close file viewer modal
 */
function closeFileViewer() {
    const modal = document.getElementById('file-viewer-modal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = 'auto';
        document.getElementById('file-viewer-content').innerHTML = '';
    }
}

/**
 * Create file viewer modal structure
 */
function createFileViewerModal() {
    const modalHtml = `
        <div id="file-viewer-modal">
            <div class="file-viewer-container">
                <div class="file-viewer-header">
                    <h3 id="file-viewer-title">Document Preview</h3>
                    <div class="file-viewer-actions">
                        <button class="file-viewer-btn file-viewer-close" onclick="closeFileViewer()" title="Close (ESC)">
                            <i class="fas fa-times fa-lg"></i>
                        </button>
                    </div>
                </div>
                <div class="file-viewer-content" id="file-viewer-content"></div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHtml);

    // Close modal when clicking outside
    document.getElementById('file-viewer-modal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeFileViewer();
        }
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeFileViewer();
        }
    });
}

function forceDownloadFile(url, filename) {
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
