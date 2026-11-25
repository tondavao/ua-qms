/**
 * UA QMS Interactive Document Editor & Revision Annotator
 * Supports:
 * - DOCX: Client-side conversion via Mammoth.js into a full rich-text editor with highlights,
 *   strikethroughs, inline maroon revision edits, and anchored comments.
 * - PDF & Images: Visual page annotator (highlight boxes, inline edit callouts, strikethrough lines,
 *   freehand pen) + optional PDF text extraction into rich-text editor.
 * - Read-only Revision View for Unit/Dean submitters showing all highlights, edits, and notes clearly.
 */
(function() {
    if (window.QmsDocEditor) return;

    const MAMMOTH_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.8.0/mammoth.browser.min.js';
    const PDFJS_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
    const PDFJS_WORKER = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    const scriptCache = {};
    function loadExternalScript(url) {
        if (scriptCache[url]) return scriptCache[url];
        scriptCache[url] = new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = url;
            s.onload = () => resolve();
            s.onerror = () => reject(new Error('Failed to load ' + url));
            document.head.appendChild(s);
        });
        return scriptCache[url];
    }

    async function ensureMammoth() {
        if (typeof window.mammoth !== 'undefined') return window.mammoth;
        await loadExternalScript(MAMMOTH_CDN);
        return window.mammoth;
    }

    async function ensurePdfJs() {
        if (typeof window.pdfjsLib !== 'undefined') {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER;
            return window.pdfjsLib;
        }
        await loadExternalScript(PDFJS_CDN);
        if (window.pdfjsLib) {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER;
        }
        return window.pdfjsLib;
    }

    function normalizeDocPath(filePath) {
        if (!filePath) return '';
        let clean = String(filePath).replace(/\\/g, '/');
        if (clean.startsWith('http://') || clean.startsWith('https://')) return clean;
        const isInDashboard = window.location.pathname.includes('/dashboard/');
        if (isInDashboard && !clean.startsWith('../')) {
            clean = '../../' + clean.replace(/^\/+/, '');
        }
        return clean;
    }

    function esc(str) {
        return String(str ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[ch]));
    }

    function injectStyles() {
        if (document.getElementById('qms-doc-editor-styles')) return;
        const style = document.createElement('style');
        style.id = 'qms-doc-editor-styles';
        style.textContent = `
            #qms-doc-editor-modal { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; }
            .qms-paper-sheet {
                background: #ffffff;
                width: 100%;
                max-width: 850px;
                min-height: 980px;
                margin: 0 auto;
                padding: 44px 52px;
                box-shadow: 0 4px 24px rgba(0,0,0,0.12);
                border-radius: 6px;
                color: #111827;
                font-size: 14px;
                line-height: 1.65;
                outline: none;
            }
            @media (max-width: 768px) {
                .qms-paper-sheet { padding: 20px 18px; min-height: 500px; }
            }
            .qms-paper-sheet h1 { font-size: 1.5rem; font-weight: 700; margin: 0.75em 0 0.4em; }
            .qms-paper-sheet h2 { font-size: 1.25rem; font-weight: 700; margin: 0.75em 0 0.4em; }
            .qms-paper-sheet h3 { font-size: 1.1rem; font-weight: 700; margin: 0.6em 0 0.35em; }
            .qms-paper-sheet p { margin: 0 0 0.65em; }
            .qms-paper-sheet ul { list-style: disc; padding-left: 1.5em; margin: 0.5em 0; }
            .qms-paper-sheet ol { list-style: decimal; padding-left: 1.5em; margin: 0.5em 0; }
            .qms-paper-sheet table { border-collapse: collapse; width: 100%; margin: 12px 0; font-size: 13px; }
            .qms-paper-sheet th, .qms-paper-sheet td { border: 1px solid #9ca3af; padding: 6px 10px; vertical-align: top; }
            .qms-paper-sheet th { background: #f3f4f6; font-weight: 700; }
            .qms-paper-sheet img { max-width: 100%; height: auto; }
            .qms-paper-sheet mark, .qms-paper-sheet .rev-hl-yellow { background: #fef08a; padding: 1px 2px; border-radius: 2px; }
            .qms-paper-sheet .rev-hl-red { background: #fecaca; padding: 1px 2px; border-radius: 2px; }
            .qms-paper-sheet .rev-hl-green { background: #bbf7d0; padding: 1px 2px; border-radius: 2px; }
            .qms-paper-sheet .rev-edit-text { color: #800000; font-weight: 700; text-decoration: underline; background: #fff1f2; padding: 0 3px; border-radius: 2px; }
            .qms-paper-sheet .rev-strike { color: #b91c1c; text-decoration: line-through; background: #fee2e2; padding: 0 2px; }
            .qms-paper-sheet .rev-comment-anchor { background: #fde047; border-bottom: 2px solid #800000; padding: 1px 2px; cursor: pointer; }
            .qms-paper-sheet .rev-comment-tag { display: inline-block; background: #800000; color: #fff; font-size: 10px; font-weight: 700; padding: 0 5px; border-radius: 999px; margin-left: 3px; vertical-align: super; user-select: none; }
            .qms-tool-btn {
                display: inline-flex; align-items: center; gap: 5px;
                padding: 5px 9px; border-radius: 6px; font-size: 11px; font-weight: 700;
                border: 1px solid #e5e7eb; background: #fff; color: #374151;
                cursor: pointer; transition: all 0.15s ease; white-space: nowrap;
            }
            .qms-tool-btn:hover { background: #f3f4f6; border-color: #d1d5db; }
            .qms-tool-btn.active { background: #800000; color: #fff; border-color: #800000; }
            .qms-visual-page-wrap {
                position: relative;
                display: inline-block;
                background: #fff;
                box-shadow: 0 4px 20px rgba(0,0,0,0.18);
                margin: 0 auto 20px;
                user-select: none;
            }
            .qms-visual-overlay {
                position: absolute;
                inset: 0;
                z-index: 10;
            }
            .qms-annot-rect {
                position: absolute;
                pointer-events: auto;
                border-radius: 2px;
                cursor: pointer;
            }
            .qms-annot-rect:hover { outline: 2px solid #800000; }
            .qms-annot-callout {
                position: absolute;
                z-index: 20;
                min-width: 110px;
                max-width: 260px;
                background: #fffbeb;
                border: 2px solid #800000;
                color: #7f1d1d;
                border-radius: 6px;
                padding: 4px 8px;
                font-size: 12px;
                font-weight: 700;
                box-shadow: 0 4px 12px rgba(0,0,0,0.18);
                white-space: pre-wrap;
                word-break: break-word;
            }
            .qms-annot-del-btn {
                position: absolute;
                top: -9px;
                right: -9px;
                width: 18px;
                height: 18px;
                border-radius: 999px;
                background: #800000;
                color: #fff;
                font-size: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                border: 1px solid #fff;
            }
        `;
        document.head.appendChild(style);
    }

    class QmsDocEditorController {
        constructor() {
            this.state = {
                documentId: null,
                mode: 'view', // 'edit' or 'view'
                doc: null,
                annotation: null,
                activeTab: 'rich', // 'rich', 'visual', 'original'
                visualTool: 'highlight-yellow', // 'select', 'highlight-yellow', 'highlight-red', 'highlight-green', 'note', 'strike', 'pen'
                comments: [],
                visualItems: [], // { id, page, type, color, x, y, w, h, text, points }
                rawExtractedHtml: '',
                hasUnsavedChanges: false,
                onSavedCallback: null,
                fileExt: ''
            };
            injectStyles();
            this.ensureModalDom();
        }

        ensureModalDom() {
            if (document.getElementById('qms-doc-editor-modal')) return;
            const html = `
                <div id="qms-doc-editor-modal" class="fixed inset-0 bg-black/75 z-[10050] hidden items-center justify-center p-2 md:p-4 backdrop-blur-sm">
                    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-[1400px] h-[95vh] flex flex-col overflow-hidden">
                        <!-- Top Header -->
                        <div class="px-4 md:px-6 py-3 bg-[#800000] text-white flex items-center justify-between gap-3 flex-shrink-0">
                            <div class="min-w-0 flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg bg-white/15 flex items-center justify-center flex-shrink-0">
                                    <i id="qms-ed-header-icon" class="fas fa-highlighter text-sm"></i>
                                </div>
                                <div class="min-w-0">
                                    <h3 id="qms-ed-title" class="font-bold text-sm md:text-base truncate">Document Review Editor</h3>
                                    <p id="qms-ed-subtitle" class="text-[11px] text-red-100 truncate">Loading document...</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <div id="qms-ed-view-tabs" class="hidden sm:flex items-center bg-black/20 rounded-lg p-0.5 text-xs">
                                    <button type="button" id="qms-tab-rich" onclick="QmsDocEditor.switchView('rich')" class="px-2.5 py-1 rounded-md font-bold transition">Editable Text</button>
                                    <button type="button" id="qms-tab-visual" onclick="QmsDocEditor.switchView('visual')" class="px-2.5 py-1 rounded-md font-bold transition">Page Annotator</button>
                                    <button type="button" id="qms-tab-original" onclick="QmsDocEditor.switchView('original')" class="px-2.5 py-1 rounded-md font-bold transition">Original File</button>
                                </div>
                                <a id="qms-ed-download-btn" href="#" target="_blank" class="hidden px-3 py-1.5 bg-white/15 hover:bg-white/25 text-white rounded-lg text-xs font-bold transition items-center gap-1.5">
                                    <i class="fas fa-download"></i> <span class="hidden md:inline">Download Copy</span>
                                </a>
                                <button type="button" id="qms-ed-save-btn" onclick="QmsDocEditor.save()" class="hidden px-3.5 py-1.5 bg-[#FFD700] hover:bg-yellow-400 text-[#800000] rounded-lg text-xs font-extrabold shadow transition items-center gap-1.5">
                                    <i class="fas fa-save"></i> <span>Save Edits</span>
                                </button>
                                <button type="button" onclick="QmsDocEditor.close()" class="px-2.5 py-1.5 bg-red-900 hover:bg-red-950 text-white rounded-lg text-xs font-bold transition">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Reviewer Banner (Unit Read-Only View) -->
                        <div id="qms-ed-unit-banner" class="hidden px-5 py-2.5 bg-amber-50 border-b border-amber-200 flex flex-wrap items-center justify-between gap-2 text-xs text-amber-950 flex-shrink-0">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-highlighter text-[#800000]"></i>
                                <span class="font-bold">QMO Reviewer Highlights &amp; Edits:</span>
                                <span id="qms-ed-unit-banner-text">Highlighted areas, strikethroughs, and maroon notes show what needs to be revised.</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm inline-block bg-[#fef08a] border border-amber-300"></span> Revise</span>
                                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm inline-block bg-[#fecaca] border border-red-300"></span> Remove</span>
                                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm inline-block bg-[#bbf7d0] border border-green-300"></span> OK/Add</span>
                            </div>
                        </div>

                        <!-- Rich Text Toolbar (Edit Mode) -->
                        <div id="qms-ed-rich-toolbar" class="hidden px-4 py-2 bg-gray-50 border-b border-gray-200 flex flex-wrap items-center gap-1.5 flex-shrink-0">
                            <span class="text-[10px] font-bold uppercase text-gray-400 mr-1">Highlight:</span>
                            <button type="button" class="qms-tool-btn" onclick="QmsDocEditor.applyHighlight('#fef08a')" title="Highlight Yellow (Needs Revision)">
                                <span class="w-3 h-3 rounded-sm bg-[#fef08a] border border-yellow-400"></span> Yellow
                            </button>
                            <button type="button" class="qms-tool-btn" onclick="QmsDocEditor.applyHighlight('#fecaca')" title="Highlight Red (Remove / Error)">
                                <span class="w-3 h-3 rounded-sm bg-[#fecaca] border border-red-400"></span> Red
                            </button>
                            <button type="button" class="qms-tool-btn" onclick="QmsDocEditor.applyHighlight('#bbf7d0')" title="Highlight Green (Suggested / Good)">
                                <span class="w-3 h-3 rounded-sm bg-[#bbf7d0] border border-green-400"></span> Green
                            </button>
                            <button type="button" class="qms-tool-btn" onclick="QmsDocEditor.clearHighlight()" title="Clear Highlight">
                                <i class="fas fa-eraser text-gray-500"></i> Clear
                            </button>
                            <div class="w-px h-5 bg-gray-300 mx-1"></div>
                            <button type="button" class="qms-tool-btn" onclick="QmsDocEditor.execRichCmd('bold')" title="Bold"><i class="fas fa-bold"></i></button>
                            <button type="button" class="qms-tool-btn" onclick="QmsDocEditor.execRichCmd('italic')" title="Italic"><i class="fas fa-italic"></i></button>
                            <button type="button" class="qms-tool-btn" onclick="QmsDocEditor.execRichCmd('underline')" title="Underline"><i class="fas fa-underline"></i></button>
                            <button type="button" class="qms-tool-btn text-red-700" onclick="QmsDocEditor.applyStrikethrough()" title="Mark for Deletion (Strikethrough)">
                                <i class="fas fa-strikethrough"></i> Strike
                            </button>
                            <div class="w-px h-5 bg-gray-300 mx-1"></div>
                            <button type="button" class="qms-tool-btn text-[#800000] border-red-200 bg-red-50" onclick="QmsDocEditor.insertRevisionEdit()" title="Insert Inline Reviewer Edit">
                                <i class="fas fa-pen-fancy"></i> Insert Edit
                            </button>
                            <button type="button" class="qms-tool-btn text-amber-800 border-amber-300 bg-amber-50" onclick="QmsDocEditor.addCommentFromSelection()" title="Highlight Selected Text & Add Comment">
                                <i class="fas fa-comment-medical"></i> Add Comment
                            </button>
                            <div class="ml-auto flex items-center gap-1.5">
                                <button type="button" class="qms-tool-btn text-gray-600" onclick="QmsDocEditor.resetToOriginal()" title="Reload original unmodified document">
                                    <i class="fas fa-undo"></i> Reset Original
                                </button>
                            </div>
                        </div>

                        <!-- Visual Annotator Toolbar (PDF / Image Edit Mode) -->
                        <div id="qms-ed-visual-toolbar" class="hidden px-4 py-2 bg-gray-50 border-b border-gray-200 flex flex-wrap items-center gap-1.5 flex-shrink-0">
                            <span class="text-[10px] font-bold uppercase text-gray-400 mr-1">Tools:</span>
                            <button type="button" data-vtool="highlight-yellow" class="qms-tool-btn active" onclick="QmsDocEditor.setVisualTool('highlight-yellow')">
                                <span class="w-3 h-3 rounded-sm bg-[#fef08a] border border-yellow-500"></span> Highlight Yellow
                            </button>
                            <button type="button" data-vtool="highlight-red" class="qms-tool-btn" onclick="QmsDocEditor.setVisualTool('highlight-red')">
                                <span class="w-3 h-3 rounded-sm bg-[#fecaca] border border-red-500"></span> Highlight Red
                            </button>
                            <button type="button" data-vtool="highlight-green" class="qms-tool-btn" onclick="QmsDocEditor.setVisualTool('highlight-green')">
                                <span class="w-3 h-3 rounded-sm bg-[#bbf7d0] border border-green-500"></span> Highlight Green
                            </button>
                            <button type="button" data-vtool="note" class="qms-tool-btn" onclick="QmsDocEditor.setVisualTool('note')">
                                <i class="fas fa-sticky-note text-[#800000]"></i> Place Text Edit
                            </button>
                            <button type="button" data-vtool="strike" class="qms-tool-btn" onclick="QmsDocEditor.setVisualTool('strike')">
                                <i class="fas fa-strikethrough text-red-700"></i> Strikethrough
                            </button>
                            <button type="button" data-vtool="pen" class="qms-tool-btn" onclick="QmsDocEditor.setVisualTool('pen')">
                                <i class="fas fa-pen text-[#800000]"></i> Freehand
                            </button>
                            <div class="ml-auto flex items-center gap-1.5">
                                <button type="button" class="qms-tool-btn" onclick="QmsDocEditor.undoVisual()">
                                    <i class="fas fa-undo"></i> Undo
                                </button>
                                <button type="button" class="qms-tool-btn text-red-700" onclick="QmsDocEditor.clearVisual()">
                                    <i class="fas fa-trash-alt"></i> Clear Markups
                                </button>
                            </div>
                        </div>

                        <!-- Main Workspace (Canvas/Paper + Comments Sidebar) -->
                        <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">
                            <!-- Document Stage -->
                            <div id="qms-ed-stage" class="flex-1 overflow-auto bg-gray-200 p-4 md:p-6">
                                <div class="h-full flex flex-col items-center justify-center text-gray-500">
                                    <i class="fas fa-spinner fa-spin text-3xl mb-2 text-[#800000]"></i>
                                    <p class="text-sm font-semibold">Loading document...</p>
                                </div>
                            </div>

                            <!-- Right Revision Notes & Comments Sidebar -->
                            <aside id="qms-ed-sidebar" class="w-full lg:w-80 border-t lg:border-t-0 lg:border-l border-gray-200 bg-white flex flex-col max-h-56 lg:max-h-none flex-shrink-0">
                                <div class="px-4 py-3 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
                                    <h4 class="text-xs font-bold uppercase tracking-wider text-gray-700 flex items-center gap-1.5">
                                        <i class="fas fa-comments text-[#800000]"></i> Revision Notes
                                    </h4>
                                    <span id="qms-ed-notes-count" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-50 text-[#800000] border border-red-200">0</span>
                                </div>
                                <div id="qms-ed-notes-list" class="flex-1 overflow-y-auto p-3 space-y-2.5 text-xs">
                                    <p class="text-gray-400 text-center py-6">No revision notes yet.</p>
                                </div>
                                <div id="qms-ed-add-note-box" class="hidden p-3 border-t border-gray-200 bg-gray-50 space-y-2">
                                    <textarea id="qms-ed-general-note" rows="2" placeholder="Add a revision note for the unit..." class="w-full p-2 border border-gray-300 rounded-lg text-xs focus:ring-1 focus:ring-[#800000] outline-none resize-none"></textarea>
                                    <button type="button" onclick="QmsDocEditor.addGeneralNote()" class="w-full py-1.5 bg-[#800000] hover:bg-red-900 text-white rounded-lg text-xs font-bold transition">
                                        <i class="fas fa-plus mr-1"></i> Add Note
                                    </button>
                                </div>
                            </aside>
                        </div>

                        <!-- Status Footer -->
                        <div class="px-4 py-2 bg-gray-50 border-t border-gray-200 flex items-center justify-between text-[11px] text-gray-500 flex-shrink-0">
                            <span id="qms-ed-status-msg">Ready</span>
                            <span id="qms-ed-tip-msg"></span>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', html);
            document.addEventListener('keydown', (e) => {
                const modal = document.getElementById('qms-doc-editor-modal');
                if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
                    this.close();
                }
            });
        }

        async open(documentId, mode = 'view', onSavedCallback = null) {
            this.ensureModalDom();
            this.state.documentId = Number(documentId);
            this.state.mode = mode === 'edit' ? 'edit' : 'view';
            this.state.onSavedCallback = typeof onSavedCallback === 'function' ? onSavedCallback : null;
            this.state.hasUnsavedChanges = false;
            this.state.comments = [];
            this.state.visualItems = [];
            this.state.rawExtractedHtml = '';

            const modal = document.getElementById('qms-doc-editor-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';

            const stage = document.getElementById('qms-ed-stage');
            stage.innerHTML = `<div class="h-full flex flex-col items-center justify-center text-gray-500"><i class="fas fa-spinner fa-spin text-3xl mb-2 text-[#800000]"></i><p class="text-sm font-semibold">Loading document &amp; saved edits...</p></div>`;

            try {
                const res = await fetch(`../../api/document_editor_api.php?action=get&document_id=${encodeURIComponent(documentId)}&_t=${Date.now()}`, { cache: 'no-store' });
                const data = await res.json();
                if (!data.success || !data.document) {
                    throw new Error(data.error || 'Could not load document details.');
                }

                this.state.doc = data.document;
                this.state.annotation = data.annotation;
                const ext = (data.document.file_name || '').split('.').pop().toLowerCase();
                this.state.fileExt = ext;

                // Parse saved annotations JSON
                if (data.annotation && data.annotation.annotations_json) {
                    try {
                        const parsed = JSON.parse(data.annotation.annotations_json);
                        if (Array.isArray(parsed.comments)) this.state.comments = parsed.comments;
                        if (Array.isArray(parsed.visualItems)) this.state.visualItems = parsed.visualItems;
                    } catch (e) {}
                }

                // Header info
                document.getElementById('qms-ed-title').textContent = data.document.file_name;
                document.getElementById('qms-ed-subtitle').textContent =
                    `${data.document.office_name} · ${data.document.doc_code} · Rev ${data.document.version_number}` +
                    (data.annotation && data.annotation.updated_at ? ` · Last edited ${data.annotation.updated_at}` : '');

                // Edit vs View mode chrome
                const isEdit = this.state.mode === 'edit';
                const saveBtn = document.getElementById('qms-ed-save-btn');
                const unitBanner = document.getElementById('qms-ed-unit-banner');
                const addNoteBox = document.getElementById('qms-ed-add-note-box');
                const dlBtn = document.getElementById('qms-ed-download-btn');

                saveBtn.classList.toggle('hidden', !isEdit);
                saveBtn.classList.toggle('inline-flex', isEdit);
                unitBanner.classList.toggle('hidden', isEdit);
                addNoteBox.classList.toggle('hidden', !isEdit);

                // Configure download button
                if (data.annotation && data.annotation.edited_html) {
                    dlBtn.href = `../../api/document_editor_api.php?action=download_edited&document_id=${this.state.documentId}`;
                    dlBtn.classList.remove('hidden');
                    dlBtn.classList.add('inline-flex');
                } else {
                    dlBtn.href = normalizeDocPath(data.document.file_path);
                    dlBtn.setAttribute('download', data.document.file_name);
                    dlBtn.classList.remove('hidden');
                    dlBtn.classList.add('inline-flex');
                }

                // Determine best initial view tab
                const isDocx = ext === 'docx';
                const isPdf = ext === 'pdf';
                const isImg = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
                const isTxt = ['txt', 'md', 'csv', 'html'].includes(ext);

                // Toggle tab visibility
                document.getElementById('qms-tab-rich').classList.toggle('hidden', !(isDocx || isPdf || isTxt || (data.annotation && data.annotation.edited_html)));
                document.getElementById('qms-tab-visual').classList.toggle('hidden', !(isPdf || isImg));

                if (isDocx || isTxt) {
                    this.state.activeTab = 'rich';
                } else if (isPdf || isImg) {
                    if (data.annotation && data.annotation.editor_mode === 'rich_text' && data.annotation.edited_html && this.state.visualItems.length === 0) {
                        this.state.activeTab = 'rich';
                    } else {
                        this.state.activeTab = 'visual';
                    }
                } else {
                    this.state.activeTab = 'rich';
                }

                this.renderNotesSidebar();
                await this.renderActiveTab();
            } catch (err) {
                stage.innerHTML = `<div class="max-w-md mx-auto mt-16 bg-white p-6 rounded-xl shadow text-center"><i class="fas fa-exclamation-triangle text-3xl text-red-500 mb-2"></i><h4 class="font-bold text-gray-900 mb-1">Unable to Open Document</h4><p class="text-xs text-gray-600">${esc(err.message)}</p></div>`;
            }
        }

        close() {
            if (this.state.hasUnsavedChanges && this.state.mode === 'edit') {
                if (!confirm('You have unsaved highlights or edits. Close without saving?')) {
                    return;
                }
            }
            const modal = document.getElementById('qms-doc-editor-modal');
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
            document.body.style.overflow = 'auto';
        }

        updateTabButtons() {
            ['rich', 'visual', 'original'].forEach(t => {
                const btn = document.getElementById('qms-tab-' + t);
                if (!btn) return;
                if (this.state.activeTab === t) {
                    btn.className = 'px-2.5 py-1 rounded-md font-bold transition bg-white text-[#800000] shadow-sm';
                } else {
                    btn.className = 'px-2.5 py-1 rounded-md font-bold transition text-red-100 hover:bg-white/10';
                }
            });

            const isEdit = this.state.mode === 'edit';
            document.getElementById('qms-ed-rich-toolbar').classList.toggle('hidden', !(isEdit && this.state.activeTab === 'rich'));
            document.getElementById('qms-ed-visual-toolbar').classList.toggle('hidden', !(isEdit && this.state.activeTab === 'visual'));
        }

        async switchView(tab) {
            // Preserve current rich text edits before switching tabs
            const paper = document.getElementById('qms-rich-paper');
            if (paper) {
                this.state.annotation = this.state.annotation || {};
                this.state.annotation.edited_html = paper.innerHTML;
            }
            this.state.activeTab = tab;
            await this.renderActiveTab();
        }

        async renderActiveTab() {
            this.updateTabButtons();
            const stage = document.getElementById('qms-ed-stage');
            const tip = document.getElementById('qms-ed-tip-msg');
            const doc = this.state.doc;
            const fileUrl = normalizeDocPath(doc.file_path);
            const ext = this.state.fileExt;

            if (this.state.activeTab === 'original') {
                tip.textContent = 'Viewing unmodified original submitted file.';
                if (ext === 'pdf') {
                    stage.innerHTML = `<iframe src="${esc(fileUrl)}" class="w-full h-full bg-white rounded-lg shadow border-0"></iframe>`;
                } else if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                    stage.innerHTML = `<div class="flex justify-center"><img src="${esc(fileUrl)}" class="max-w-full rounded-lg shadow-lg bg-white" alt="${esc(doc.file_name)}"></div>`;
                } else if (ext === 'docx') {
                    const html = await this.extractDocxHtml(fileUrl);
                    stage.innerHTML = `<div class="qms-paper-sheet">${html}</div>`;
                } else {
                    stage.innerHTML = `<div class="max-w-md mx-auto mt-12 bg-white p-6 rounded-xl shadow text-center"><i class="fas fa-file-alt text-4xl text-gray-400 mb-3"></i><p class="text-sm font-bold text-gray-800 mb-2">${esc(doc.file_name)}</p><a href="${esc(fileUrl)}" download="${esc(doc.file_name)}" class="inline-flex items-center gap-2 px-4 py-2 bg-[#800000] text-white rounded-lg text-xs font-bold"><i class="fas fa-download"></i> Download Original</a></div>`;
                }
                return;
            }

            if (this.state.activeTab === 'rich') {
                tip.textContent = this.state.mode === 'edit'
                    ? 'Tip: Select text to highlight, strikethrough, or attach a comment. You can also type edits directly.'
                    : 'Viewing reviewer highlights and text edits.';
                stage.innerHTML = `<div class="h-full flex flex-col items-center justify-center text-gray-500"><i class="fas fa-spinner fa-spin text-2xl mb-2 text-[#800000]"></i><p class="text-xs font-semibold">Preparing editable document...</p></div>`;

                let htmlContent = (this.state.annotation && this.state.annotation.edited_html) ? this.state.annotation.edited_html : '';
                if (!htmlContent) {
                    if (ext === 'docx') {
                        htmlContent = await this.extractDocxHtml(fileUrl);
                    } else if (ext === 'pdf') {
                        htmlContent = await this.extractPdfTextHtml(fileUrl);
                    } else if (['txt', 'md', 'csv', 'html'].includes(ext)) {
                        const r = await fetch(fileUrl);
                        const txt = await r.text();
                        htmlContent = ext === 'html' ? txt : `<pre style="white-space:pre-wrap;font-family:inherit;">${esc(txt)}</pre>`;
                    } else {
                        htmlContent = `<p><em>Direct text editing is not supported for .${esc(ext)} binary files. Use the Revision Notes panel on the right or add editable review notes below:</em></p><p><br></p>`;
                    }
                    this.state.rawExtractedHtml = htmlContent;
                }

                const editableAttr = this.state.mode === 'edit' ? 'contenteditable="true"' : 'contenteditable="false"';
                stage.innerHTML = `<div id="qms-rich-paper" class="qms-paper-sheet" ${editableAttr}>${htmlContent}</div>`;

                const paper = document.getElementById('qms-rich-paper');
                if (paper && this.state.mode === 'edit') {
                    paper.addEventListener('input', () => {
                        this.state.hasUnsavedChanges = true;
                        this.setStatus('Unsaved changes...');
                    });
                }
                return;
            }

            if (this.state.activeTab === 'visual') {
                tip.textContent = this.state.mode === 'edit'
                    ? 'Tip: Drag on any page to highlight or strike through; choose "Place Text Edit" and click to add an inline edit box.'
                    : 'Viewing visual highlights and callout edits on document pages.';
                await this.renderVisualPages(fileUrl, ext);
            }
        }

        async extractDocxHtml(fileUrl) {
            const mammoth = await ensureMammoth();
            const response = await fetch(fileUrl + (fileUrl.includes('?') ? '&' : '?') + '_t=' + Date.now());
            if (!response.ok) throw new Error('Could not fetch DOCX file.');
            const arrayBuffer = await response.arrayBuffer();
            const result = await mammoth.convertToHtml({ arrayBuffer });
            return result.value || '<p><em>(Document appears empty)</em></p>';
        }

        async extractPdfTextHtml(fileUrl) {
            const pdfjsLib = await ensurePdfJs();
            const pdf = await pdfjsLib.getDocument(fileUrl).promise;
            let html = '';
            for (let p = 1; p <= pdf.numPages; p++) {
                const page = await pdf.getPage(p);
                const tc = await page.getTextContent();
                let lines = [];
                let currentY = null;
                let lineText = '';
                for (const item of tc.items) {
                    const y = Math.round(item.transform[5]);
                    if (currentY !== null && Math.abs(y - currentY) > 6) {
                        if (lineText.trim()) lines.push(lineText.trim());
                        lineText = item.str;
                    } else {
                        lineText += (lineText && !lineText.endsWith(' ') && !item.str.startsWith(' ') ? ' ' : '') + item.str;
                    }
                    currentY = y;
                }
                if (lineText.trim()) lines.push(lineText.trim());
                html += `<div style="margin-bottom:20px;padding-bottom:16px;border-bottom:1px dashed #d1d5db;">`;
                html += `<div style="font-size:11px;font-weight:700;color:#6b7280;margin-bottom:8px;">— Page ${p} —</div>`;
                if (lines.length === 0) {
                    html += `<p><em>(Scanned/image-only page — use the "Page Annotator" tab to highlight directly on this page.)</em></p>`;
                } else {
                    html += lines.map(l => `<p>${esc(l)}</p>`).join('');
                }
                html += `</div>`;
            }
            return html;
        }

        async renderVisualPages(fileUrl, ext) {
            const stage = document.getElementById('qms-ed-stage');
            stage.innerHTML = `<div class="h-full flex flex-col items-center justify-center text-gray-500"><i class="fas fa-spinner fa-spin text-2xl mb-2 text-[#800000]"></i><p class="text-xs font-semibold">Rendering pages...</p></div>`;

            const container = document.createElement('div');
            container.className = 'flex flex-col items-center';
            container.id = 'qms-visual-pages-container';

            if (ext === 'pdf') {
                const pdfjsLib = await ensurePdfJs();
                const pdf = await pdfjsLib.getDocument(fileUrl).promise;
                stage.innerHTML = '';
                stage.appendChild(container);

                for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
                    const page = await pdf.getPage(pageNum);
                    const viewport = page.getViewport({ scale: 1.35 });

                    const wrap = document.createElement('div');
                    wrap.className = 'qms-visual-page-wrap';
                    wrap.dataset.page = String(pageNum);
                    wrap.style.width = viewport.width + 'px';
                    wrap.style.height = viewport.height + 'px';

                    const canvas = document.createElement('canvas');
                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    canvas.style.display = 'block';
                    wrap.appendChild(canvas);

                    const overlay = document.createElement('div');
                    overlay.className = 'qms-visual-overlay';
                    overlay.dataset.page = String(pageNum);
                    wrap.appendChild(overlay);

                    const pageLabel = document.createElement('div');
                    pageLabel.className = 'text-[10px] font-bold text-gray-500 mb-1.5';
                    pageLabel.textContent = `Page ${pageNum} of ${pdf.numPages}`;
                    container.appendChild(pageLabel);
                    container.appendChild(wrap);

                    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
                    this.bindVisualOverlayEvents(overlay, pageNum);
                    this.renderPageVisualItems(overlay, pageNum);
                }
            } else {
                // Image page
                stage.innerHTML = '';
                stage.appendChild(container);
                const wrap = document.createElement('div');
                wrap.className = 'qms-visual-page-wrap';
                wrap.dataset.page = '1';

                const img = document.createElement('img');
                img.src = fileUrl;
                img.style.maxWidth = '920px';
                img.style.display = 'block';
                img.draggable = false;
                wrap.appendChild(img);

                const overlay = document.createElement('div');
                overlay.className = 'qms-visual-overlay';
                overlay.dataset.page = '1';
                wrap.appendChild(overlay);
                container.appendChild(wrap);

                img.onload = () => {
                    this.bindVisualOverlayEvents(overlay, 1);
                    this.renderPageVisualItems(overlay, 1);
                };
            }
        }

        setVisualTool(tool) {
            this.state.visualTool = tool;
            document.querySelectorAll('#qms-ed-visual-toolbar [data-vtool]').forEach(b => {
                b.classList.toggle('active', b.getAttribute('data-vtool') === tool);
            });
        }

        bindVisualOverlayEvents(overlay, pageNum) {
            if (this.state.mode !== 'edit') return;
            overlay.style.cursor = 'crosshair';

            let dragging = false;
            let startX = 0, startY = 0;
            let tempEl = null;
            let penPoints = [];

            const getRelPos = (e) => {
                const rect = overlay.getBoundingClientRect();
                return {
                    xPct: Math.max(0, Math.min(100, ((e.clientX - rect.left) / rect.width) * 100)),
                    yPct: Math.max(0, Math.min(100, ((e.clientY - rect.top) / rect.height) * 100))
                };
            };

            overlay.addEventListener('mousedown', (e) => {
                if (e.target !== overlay) return;
                const pos = getRelPos(e);
                const tool = this.state.visualTool;

                if (tool === 'note') {
                    const noteText = prompt('Enter text edit / correction callout to place here:');
                    if (noteText && noteText.trim()) {
                        const id = 'v_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
                        this.state.visualItems.push({
                            id, page: pageNum, type: 'note', x: pos.xPct, y: pos.yPct, text: noteText.trim()
                        });
                        this.state.comments.push({
                            id, page: pageNum, quote: `Page ${pageNum} Text Edit`, note: noteText.trim()
                        });
                        this.state.hasUnsavedChanges = true;
                        this.setStatus('Unsaved changes...');
                        this.renderPageVisualItems(overlay, pageNum);
                        this.renderNotesSidebar();
                    }
                    return;
                }

                dragging = true;
                startX = pos.xPct;
                startY = pos.yPct;

                if (tool === 'pen') {
                    penPoints = [[startX, startY]];
                    return;
                }

                tempEl = document.createElement('div');
                tempEl.className = 'qms-annot-rect';
                if (tool.startsWith('highlight-')) {
                    const colorMap = {
                        'highlight-yellow': 'rgba(254, 240, 138, 0.48)',
                        'highlight-red': 'rgba(254, 202, 202, 0.52)',
                        'highlight-green': 'rgba(187, 247, 208, 0.48)'
                    };
                    tempEl.style.background = colorMap[tool];
                    tempEl.style.border = '1px dashed rgba(128,0,0,0.4)';
                } else if (tool === 'strike') {
                    tempEl.style.background = 'rgba(220, 38, 38, 0.85)';
                }
                overlay.appendChild(tempEl);
            });

            overlay.addEventListener('mousemove', (e) => {
                if (!dragging) return;
                const pos = getRelPos(e);
                const tool = this.state.visualTool;
                if (tool === 'pen') {
                    penPoints.push([pos.xPct, pos.yPct]);
                    this.renderPageVisualItems(overlay, pageNum, penPoints);
                    return;
                }
                if (!tempEl) return;
                const left = Math.min(startX, pos.xPct);
                const top = Math.min(startY, pos.yPct);
                const width = Math.abs(pos.xPct - startX);
                const height = tool === 'strike' ? 0.55 : Math.abs(pos.yPct - startY);
                tempEl.style.left = left + '%';
                tempEl.style.top = (tool === 'strike' ? startY : top) + '%';
                tempEl.style.width = width + '%';
                tempEl.style.height = height + '%';
            });

            const finishDrag = (e) => {
                if (!dragging) return;
                dragging = false;
                const pos = getRelPos(e);
                const tool = this.state.visualTool;

                if (tempEl) {
                    tempEl.remove();
                    tempEl = null;
                }

                if (tool === 'pen') {
                    if (penPoints.length > 2) {
                        this.state.visualItems.push({
                            id: 'v_' + Date.now(), page: pageNum, type: 'pen', color: '#dc2626', points: penPoints
                        });
                        this.state.hasUnsavedChanges = true;
                        this.setStatus('Unsaved changes...');
                    }
                    penPoints = [];
                    this.renderPageVisualItems(overlay, pageNum);
                    return;
                }

                const left = Math.min(startX, pos.xPct);
                const top = tool === 'strike' ? startY : Math.min(startY, pos.yPct);
                const width = Math.abs(pos.xPct - startX);
                const height = tool === 'strike' ? 0.6 : Math.abs(pos.yPct - startY);

                if (width < 0.8 && height < 0.8) return;

                const colorMap = {
                    'highlight-yellow': 'yellow',
                    'highlight-red': 'red',
                    'highlight-green': 'green'
                };
                const item = {
                    id: 'v_' + Date.now() + '_' + Math.floor(Math.random() * 1000),
                    page: pageNum,
                    type: tool === 'strike' ? 'strike' : 'highlight',
                    color: colorMap[tool] || 'red',
                    x: left, y: top, w: width, h: height,
                    note: ''
                };
                this.state.visualItems.push(item);
                this.state.hasUnsavedChanges = true;
                this.setStatus('Unsaved changes...');
                this.renderPageVisualItems(overlay, pageNum);
            };

            overlay.addEventListener('mouseup', finishDrag);
            overlay.addEventListener('mouseleave', finishDrag);
        }

        renderPageVisualItems(overlay, pageNum, livePenPoints = null) {
            overlay.innerHTML = '';
            const items = this.state.visualItems.filter(it => Number(it.page) === Number(pageNum));
            const isEdit = this.state.mode === 'edit';

            // SVG layer for freehand pen strokes
            const penItems = items.filter(it => it.type === 'pen');
            if (penItems.length > 0 || (livePenPoints && livePenPoints.length > 1)) {
                const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.setAttribute('viewBox', '0 0 100 100');
                svg.setAttribute('preserveAspectRatio', 'none');
                svg.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;pointer-events:none;';
                const allStrokes = [...penItems.map(p => p.points), ...(livePenPoints ? [livePenPoints] : [])];
                allStrokes.forEach(pts => {
                    if (!pts || pts.length < 2) return;
                    const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
                    poly.setAttribute('fill', 'none');
                    poly.setAttribute('stroke', '#b91c1c');
                    poly.setAttribute('stroke-width', '0.45');
                    poly.setAttribute('stroke-linecap', 'round');
                    poly.setAttribute('stroke-linejoin', 'round');
                    poly.setAttribute('points', pts.map(p => `${p[0]},${p[1]}`).join(' '));
                    svg.appendChild(poly);
                });
                overlay.appendChild(svg);
            }

            const bgMap = {
                yellow: 'rgba(254, 240, 138, 0.45)',
                red: 'rgba(254, 202, 202, 0.50)',
                green: 'rgba(187, 247, 208, 0.45)'
            };

            items.forEach(it => {
                if (it.type === 'highlight' || it.type === 'strike') {
                    const div = document.createElement('div');
                    div.className = 'qms-annot-rect';
                    div.style.left = it.x + '%';
                    div.style.top = it.y + '%';
                    div.style.width = it.w + '%';
                    div.style.height = (it.type === 'strike' ? 0.55 : it.h) + '%';
                    div.style.background = it.type === 'strike' ? 'rgba(185, 28, 28, 0.9)' : (bgMap[it.color] || bgMap.yellow);
                    if (it.note) div.title = it.note;

                    if (isEdit) {
                        div.addEventListener('click', (e) => {
                            e.stopPropagation();
                            if (confirm('Remove this markup?')) {
                                this.state.visualItems = this.state.visualItems.filter(v => v.id !== it.id);
                                this.state.hasUnsavedChanges = true;
                                this.renderPageVisualItems(overlay, pageNum);
                            }
                        });
                    }
                    overlay.appendChild(div);
                } else if (it.type === 'note') {
                    const box = document.createElement('div');
                    box.className = 'qms-annot-callout';
                    box.style.left = Math.min(72, it.x) + '%';
                    box.style.top = it.y + '%';
                    box.textContent = it.text;
                    if (isEdit) {
                        const del = document.createElement('span');
                        del.className = 'qms-annot-del-btn';
                        del.innerHTML = '&times;';
                        del.title = 'Delete callout';
                        del.addEventListener('click', (e) => {
                            e.stopPropagation();
                            this.state.visualItems = this.state.visualItems.filter(v => v.id !== it.id);
                            this.state.comments = this.state.comments.filter(c => c.id !== it.id);
                            this.state.hasUnsavedChanges = true;
                            this.renderPageVisualItems(overlay, pageNum);
                            this.renderNotesSidebar();
                        });
                        box.appendChild(del);
                    }
                    overlay.appendChild(box);
                }
            });
        }

        undoVisual() {
            if (this.state.visualItems.length === 0) return;
            const removed = this.state.visualItems.pop();
            if (removed) {
                this.state.comments = this.state.comments.filter(c => c.id !== removed.id);
                this.state.hasUnsavedChanges = true;
                this.rerenderAllVisualOverlays();
                this.renderNotesSidebar();
            }
        }

        clearVisual() {
            if (!confirm('Clear all visual markups on this document?')) return;
            this.state.visualItems = [];
            this.state.hasUnsavedChanges = true;
            this.rerenderAllVisualOverlays();
        }

        rerenderAllVisualOverlays() {
            document.querySelectorAll('.qms-visual-overlay').forEach(ov => {
                this.renderPageVisualItems(ov, Number(ov.dataset.page));
            });
        }

        // --- Rich Text Commands ---
        execRichCmd(cmd, value = null) {
            const paper = document.getElementById('qms-rich-paper');
            if (!paper) return;
            paper.focus();
            document.execCommand(cmd, false, value);
            this.state.hasUnsavedChanges = true;
            this.setStatus('Unsaved changes...');
        }

        applyHighlight(hexColor) {
            const paper = document.getElementById('qms-rich-paper');
            if (!paper) return;
            const sel = window.getSelection();
            if (!sel || sel.isCollapsed) {
                alert('Please select text in the document first to highlight it.');
                return;
            }
            paper.focus();
            if (!document.execCommand('hiliteColor', false, hexColor)) {
                document.execCommand('backColor', false, hexColor);
            }
            this.state.hasUnsavedChanges = true;
            this.setStatus('Unsaved changes...');
        }

        clearHighlight() {
            const paper = document.getElementById('qms-rich-paper');
            if (!paper) return;
            paper.focus();
            if (!document.execCommand('hiliteColor', false, 'transparent')) {
                document.execCommand('backColor', false, 'transparent');
            }
            this.state.hasUnsavedChanges = true;
        }

        applyStrikethrough() {
            const paper = document.getElementById('qms-rich-paper');
            if (!paper) return;
            const sel = window.getSelection();
            if (!sel || sel.isCollapsed) {
                alert('Select the text you want to strike through.');
                return;
            }
            paper.focus();
            document.execCommand('strikeThrough', false, null);
            document.execCommand('foreColor', false, '#b91c1c');
            this.state.hasUnsavedChanges = true;
            this.setStatus('Unsaved changes...');
        }

        insertRevisionEdit() {
            const paper = document.getElementById('qms-rich-paper');
            if (!paper) return;
            const editText = prompt('Enter suggested replacement / revision text:');
            if (!editText || !editText.trim()) return;
            paper.focus();
            const html = ` <span class="rev-edit-text">[EDIT: ${esc(editText.trim())}]</span> `;
            document.execCommand('insertHTML', false, html);
            this.state.hasUnsavedChanges = true;
            this.setStatus('Unsaved changes...');
        }

        addCommentFromSelection() {
            const paper = document.getElementById('qms-rich-paper');
            if (!paper) return;
            const sel = window.getSelection();
            const selectedText = sel ? sel.toString().trim() : '';
            if (!selectedText) {
                alert('Select text in the document to attach a comment, or use the "Add Note" box on the right.');
                return;
            }
            const note = prompt(`Add revision comment for:\n"${selectedText.substring(0, 80)}${selectedText.length > 80 ? '...' : ''}"`);
            if (!note || !note.trim()) return;

            const commentIdx = this.state.comments.length + 1;
            const cid = 'c_' + Date.now();
            const range = sel.getRangeAt(0);
            const span = document.createElement('span');
            span.className = 'rev-comment-anchor';
            span.id = 'anchor-' + cid;
            span.title = note.trim();
            try {
                range.surroundContents(span);
            } catch (e) {
                span.textContent = selectedText;
                range.deleteContents();
                range.insertNode(span);
            }
            const tag = document.createElement('span');
            tag.className = 'rev-comment-tag';
            tag.textContent = String(commentIdx);
            span.appendChild(tag);

            this.state.comments.push({
                id: cid,
                quote: selectedText.substring(0, 120),
                note: note.trim()
            });
            this.state.hasUnsavedChanges = true;
            this.setStatus('Unsaved changes...');
            this.renderNotesSidebar();
        }

        addGeneralNote() {
            const input = document.getElementById('qms-ed-general-note');
            if (!input) return;
            const text = input.value.trim();
            if (!text) return;
            this.state.comments.push({
                id: 'n_' + Date.now(),
                quote: '',
                note: text
            });
            input.value = '';
            this.state.hasUnsavedChanges = true;
            this.setStatus('Unsaved changes...');
            this.renderNotesSidebar();
        }

        deleteComment(id) {
            this.state.comments = this.state.comments.filter(c => c.id !== id);
            // Also remove anchor highlight tag if present
            const anchor = document.getElementById('anchor-' + id);
            if (anchor) {
                const tag = anchor.querySelector('.rev-comment-tag');
                if (tag) tag.remove();
                const parent = anchor.parentNode;
                while (anchor.firstChild) parent.insertBefore(anchor.firstChild, anchor);
                anchor.remove();
            }
            this.state.hasUnsavedChanges = true;
            this.renderNotesSidebar();
        }

        scrollToComment(id) {
            const anchor = document.getElementById('anchor-' + id);
            if (anchor) {
                anchor.scrollIntoView({ behavior: 'smooth', block: 'center' });
                anchor.style.outline = '2px solid #800000';
                setTimeout(() => { anchor.style.outline = ''; }, 1500);
            }
        }

        renderNotesSidebar() {
            const list = document.getElementById('qms-ed-notes-list');
            const badge = document.getElementById('qms-ed-notes-count');
            const isEdit = this.state.mode === 'edit';
            const totalNotes = this.state.comments.length;
            if (badge) badge.textContent = String(totalNotes);

            if (!list) return;
            const latestRemarks = this.state.doc && this.state.doc.latest_remarks ? this.state.doc.latest_remarks : '';

            let html = '';
            if (latestRemarks) {
                html += `
                    <div class="p-2.5 rounded-lg bg-red-50 border border-red-200 text-red-900">
                        <div class="text-[10px] font-bold uppercase text-[#800000] mb-1"><i class="fas fa-comment-dots mr-1"></i> Overall Review Remarks</div>
                        <p class="leading-relaxed">${esc(latestRemarks)}</p>
                    </div>
                `;
            }

            if (totalNotes === 0) {
                html += `<p class="text-gray-400 text-center py-6">${isEdit ? 'Select text and click "Add Comment" or add a note below.' : 'No inline comments attached.'}</p>`;
            } else {
                html += this.state.comments.map((c, idx) => `
                    <div class="p-2.5 rounded-lg bg-amber-50/70 border border-amber-200 hover:border-[#800000] transition cursor-pointer" onclick="QmsDocEditor.scrollToComment('${esc(c.id)}')">
                        <div class="flex items-start justify-between gap-2 mb-1">
                            <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-full bg-[#800000] text-white text-[10px] font-bold">#${idx + 1}</span>
                            ${isEdit ? `<button type="button" onclick="event.stopPropagation(); QmsDocEditor.deleteComment('${esc(c.id)}')" class="text-gray-400 hover:text-red-600"><i class="fas fa-times"></i></button>` : ''}
                        </div>
                        ${c.quote ? `<div class="text-[11px] bg-[#fef08a] text-gray-900 px-1.5 py-0.5 rounded mb-1 italic truncate" title="${esc(c.quote)}">"${esc(c.quote)}"</div>` : ''}
                        <p class="text-gray-800 font-semibold leading-snug">${esc(c.note)}</p>
                    </div>
                `).join('');
            }
            list.innerHTML = html;
        }

        async resetToOriginal() {
            if (!confirm('Reload the original submitted document? Current unsaved text edits will be replaced.')) return;
            const fileUrl = normalizeDocPath(this.state.doc.file_path);
            let freshHtml = '';
            if (this.state.fileExt === 'docx') {
                freshHtml = await this.extractDocxHtml(fileUrl);
            } else if (this.state.fileExt === 'pdf') {
                freshHtml = await this.extractPdfTextHtml(fileUrl);
            }
            const paper = document.getElementById('qms-rich-paper');
            if (paper && freshHtml) {
                paper.innerHTML = freshHtml;
                this.state.hasUnsavedChanges = true;
                this.setStatus('Reloaded original text (click Save Edits to store).');
            }
        }

        async save() {
            const btn = document.getElementById('qms-ed-save-btn');
            const origHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            try {
                const paper = document.getElementById('qms-rich-paper');
                const editedHtml = paper ? paper.innerHTML : (this.state.annotation?.edited_html || null);
                const hasVisual = this.state.visualItems.length > 0;
                const hasRich = Boolean(editedHtml && editedHtml.trim());
                const editorMode = (hasVisual && hasRich) ? 'hybrid' : (hasVisual ? 'visual' : 'rich_text');

                const payload = {
                    document_id: this.state.documentId,
                    edited_html: editedHtml,
                    annotations_json: JSON.stringify({
                        comments: this.state.comments,
                        visualItems: this.state.visualItems
                    }),
                    editor_mode: editorMode
                };

                const res = await fetch('../../api/document_editor_api.php?action=save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) {
                    throw new Error(data.error || 'Failed to save edits.');
                }

                this.state.hasUnsavedChanges = false;
                this.state.annotation = this.state.annotation || {};
                this.state.annotation.edited_html = editedHtml;
                this.state.annotation.updated_at = data.updated_at;

                // Enable edited .doc download if rich HTML exists
                const dlBtn = document.getElementById('qms-ed-download-btn');
                if (editedHtml && dlBtn) {
                    dlBtn.href = `../../api/document_editor_api.php?action=download_edited&document_id=${this.state.documentId}`;
                    dlBtn.removeAttribute('download');
                }

                this.setStatus('Saved at ' + (data.updated_at || 'just now') + '. Unit will see these edits when returned for revision.');
                if (this.state.onSavedCallback) {
                    this.state.onSavedCallback(data);
                }
            } catch (err) {
                alert('Error saving edits: ' + err.message);
                this.setStatus('Save failed.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }

        setStatus(msg) {
            const el = document.getElementById('qms-ed-status-msg');
            if (el) el.textContent = msg;
        }
    }

    window.QmsDocEditor = new QmsDocEditorController();
    window.openDocumentEditor = function(documentId, mode = 'view', onSaved = null) {
        window.QmsDocEditor.open(documentId, mode, onSaved);
    };
})();
