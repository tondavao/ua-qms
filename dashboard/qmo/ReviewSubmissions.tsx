import React, { useState, useEffect, useMemo } from 'react';
import { 
  CheckCircle, 
  RefreshCw, 
  History, 
  Download, 
  MessageSquare, 
  Search, 
  Filter, 
  AlertCircle,
  ChevronRight,
  FileText,
  Clock,
  Check,
  File,
  FileCode,
  FileSpreadsheet,
  Eye
} from 'lucide-react';
import { useAppContext } from './AppContext';
import { SubmissionStatus, DocumentType, Submission } from './types';

const ReviewSubmissions: React.FC = () => {
  const { 
    submissions, 
    units, 
    updateSubmission, 
    addNotification, 
    addAuditLog,
    targetReviewId,
    setTargetReviewId
  } = useAppContext();

  const [selectedSubId, setSelectedSubId] = useState<number | null>(null);
  const [feedback, setFeedback] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [isProcessing, setIsProcessing] = useState(false);
  const [expandedFiles, setExpandedFiles] = useState(true);

  // Auto-selection logic from targetReviewId (e.g., from notification)
  useEffect(() => {
    if (targetReviewId) {
      setSelectedSubId(targetReviewId);
      // Clear the target after selection
      setTargetReviewId(null);
    }
  }, [targetReviewId, setTargetReviewId]);

  // Filter and Sort Logic
  const reviewQueue = useMemo(() => {
    return submissions
      .filter(s => s.status === SubmissionStatus.SUBMITTED)
      .filter(s => {
        if (!searchTerm) return true;
        const unit = units.find(u => u.id === s.unit_id);
        return (
          unit?.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
          unit?.code.toLowerCase().includes(searchTerm.toLowerCase()) ||
          s.doc_type.toLowerCase().includes(searchTerm.toLowerCase())
        );
      })
      .sort((a, b) => {
        // Revisions (version > 1) float to top
        if (a.version > 1 && b.version === 1) return -1;
        if (a.version === 1 && b.version > 1) return 1;
        
        // Then sort by submittedAt (newest first)
        const dateA = a.submitted_at ? new Date(a.submitted_at).getTime() : 0;
        const dateB = b.submitted_at ? new Date(b.submitted_at).getTime() : 0;
        return dateB - dateA;
      });
  }, [submissions, units, searchTerm]);

  const selectedSubmission = useMemo(() => 
    submissions.find(s => s.id === selectedSubId), 
  [submissions, selectedSubId]);

  const selectedUnit = useMemo(() => 
    units.find(u => u.id === selectedSubmission?.unit_id),
  [units, selectedSubmission]);

  const handleAction = async (action: 'APPROVE' | 'REVISION') => {
    if (!selectedSubmission || !selectedUnit) return;
    if (action === 'REVISION' && !feedback.trim()) {
      alert('Please provide feedback for the revision request.');
      return;
    }

    setIsProcessing(true);
    try {
      const newStatus = action === 'APPROVE' ? SubmissionStatus.APPROVED : SubmissionStatus.REVISION;
      const timestamp = new Date().toLocaleString();
      
      // Update Submission
      updateSubmission(selectedSubmission.id, {
        status: newStatus,
        feedback: [...(selectedSubmission.feedback || []), feedback]
      });

      // Add Notification
      addNotification({
        userId: selectedSubmission.unit_id,
        message: `Your ${selectedSubmission.doc_type} submission has been ${action === 'APPROVE' ? 'approved' : 'returned for revision'}.`,
        documentName: selectedSubmission.doc_type,
        targetTab: 'my-submissions'
      });

      // Add Audit Log
      addAuditLog({
        action: action === 'APPROVE' ? 'Approve Document' : 'Return for Revision',
        user: 'QMO Admin',
        type: 'Submissions',
        details: `${action === 'APPROVE' ? 'Approved' : 'Returned'} ${selectedSubmission.doc_type} for ${selectedUnit.name} (v${selectedSubmission.version})`
      });

      // Reset state
      setFeedback('');
      setSelectedSubId(null);
    } finally {
      setIsProcessing(false);
    }
  };

  return (
    <div className="flex h-[calc(100vh-140px)] bg-gray-50 overflow-hidden border border-gray-200 rounded-lg shadow-sm">
      {/* Sidebar Queue */}
      <div className="w-80 flex flex-col border-r border-gray-200 bg-white">
        <div className="p-4 border-b border-gray-200 bg-gray-50">
          <h2 className="text-lg font-bold text-[#800000] flex items-center gap-2">
            <Clock className="w-5 h-5" />
            Review Queue
            <span className="ml-auto bg-[#800000] text-white text-xs px-2 py-1 rounded-full">
              {reviewQueue.length}
            </span>
          </h2>
          <div className="mt-3 relative">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
            <input 
              type="text"
              placeholder="Search unit or doc..."
              className="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 rounded-md focus:ring-1 focus:ring-[#800000] focus:border-[#800000] outline-none"
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
            />
          </div>
        </div>

        <div className="flex-1 overflow-y-auto p-2 space-y-2">
          {reviewQueue.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-full text-gray-400 p-4 text-center">
              <div className="w-12 h-12 bg-green-50 text-green-500 rounded-full flex items-center justify-center mb-2">
                <Check className="w-6 h-6" />
              </div>
              <p className="text-sm font-medium">Queue is Clear</p>
              <p className="text-xs">All submissions have been reviewed.</p>
            </div>
          ) : (
            reviewQueue.map((sub) => {
              const unit = units.find(u => u.id === sub.unit_id);
              const isRevision = sub.version > 1;
              const isSelected = selectedSubId === sub.id;

              return (
                <button
                  key={sub.id}
                  onClick={() => setSelectedSubId(sub.id)}
                  className={`w-full text-left p-3 rounded-lg border transition-all ${
                    isSelected 
                      ? 'border-[#800000] ring-1 ring-[#800000] shadow-md' 
                      : 'border-gray-200 hover:border-gray-300'
                  } ${
                    isRevision ? 'bg-amber-50' : 'bg-blue-50'
                  }`}
                >
                  <div className="flex justify-between items-start mb-1">
                    <span className="text-xs font-bold uppercase tracking-wider text-gray-500">
                      {sub.doc_type}
                    </span>
                    {isRevision && (
                      <span className="flex items-center gap-1 text-[10px] font-bold bg-amber-200 text-amber-800 px-1.5 py-0.5 rounded">
                        <RefreshCw className="w-2.5 h-2.5" />
                        V{sub.version}
                      </span>
                    )}
                  </div>
                  <h3 className="text-sm font-bold text-gray-900 truncate">
                    {unit?.name || 'Unknown Unit'}
                  </h3>
                  <div className="flex items-center gap-2 mt-2 text-[11px] text-gray-500">
                    <Clock className="w-3 h-3" />
                    {sub.submitted_at || 'Just now'}
                  </div>
                </button>
              );
            })
          )}
        </div>
      </div>

      {/* Main Review Area */}
      <div className="flex-1 flex flex-col bg-white overflow-hidden">
        {selectedSubmission ? (
          <>
            {/* Header */}
            <div className="p-6 border-b border-gray-200 flex justify-between items-center bg-white">
              <div>
                <div className="flex items-center gap-3 mb-1">
                  <h1 className="text-2xl font-bold text-gray-900">{selectedUnit?.name}</h1>
                  <span className={`px-2 py-1 rounded text-xs font-bold ${
                    selectedSubmission.version > 1 ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800'
                  }`}>
                    {selectedSubmission.doc_type} - Version {selectedSubmission.version}
                  </span>
                </div>
                <p className="text-sm text-gray-500 flex items-center gap-2">
                  <FileText className="w-4 h-4" />
                  Submitted on {selectedSubmission.submitted_at}
                </p>
              </div>
              <button className="flex items-center gap-2 px-4 py-2 bg-[#800000] text-white rounded-md hover:bg-[#600000] transition-colors font-bold text-sm shadow-sm">
                <Download className="w-4 h-4" />
                Download LATEST
              </button>
            </div>

            {/* Submitted Files Section */}
            <div className="px-6 pt-6 pb-0">
              <div className="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                <div className="bg-gray-100 px-4 py-3 border-b border-gray-200 flex items-center justify-between cursor-pointer hover:bg-gray-150 transition-colors" onClick={() => setExpandedFiles(!expandedFiles)}>
                  <div className="flex items-center gap-2">
                    <FileText className="w-4 h-4 text-gray-600" />
                    <span className="font-bold text-sm text-gray-700">Submitted Files</span>
                    <span className="ml-2 bg-gray-200 text-gray-700 text-xs px-2 py-0.5 rounded-full font-medium">
                      {selectedSubmission?.files?.length || 0}
                    </span>
                  </div>
                  <ChevronRight className={`w-4 h-4 text-gray-600 transition-transform ${expandedFiles ? 'rotate-90' : ''}`} />
                </div>
                {expandedFiles && (
                  <div className="p-4 space-y-2">
                    {selectedSubmission?.files && selectedSubmission.files.length > 0 ? (
                      selectedSubmission.files.map((file: any, idx: number) => (
                        <div key={idx} className="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-100 hover:border-gray-300 hover:bg-white transition-all group">
                          <div className="flex items-center gap-3 flex-1 min-w-0">
                            <div className="w-10 h-10 bg-blue-50 rounded-lg flex items-center justify-center flex-shrink-0">
                              <FileCode className="w-5 h-5 text-blue-600" />
                            </div>
                            <div className="min-w-0 flex-1">
                              <p className="text-sm font-medium text-gray-900 truncate">{file.name}</p>
                              <p className="text-xs text-gray-500">{file.size} • Uploaded {file.uploadedAt}</p>
                            </div>
                          </div>
                          <button className="ml-2 p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-200 rounded-lg transition-all opacity-0 group-hover:opacity-100">
                            <Eye className="w-4 h-4" />
                          </button>
                        </div>
                      ))
                    ) : (
                      <div className="p-4 text-center text-gray-400">
                        <FileText className="w-8 h-8 mx-auto mb-2 opacity-30" />
                        <p className="text-sm">No files submitted yet</p>
                      </div>
                    )}
                  </div>
                )}
              </div>
            </div>

            {/* Content Area */}
            <div className="flex-1 overflow-y-auto p-6 space-y-6 bg-gray-50">
              {selectedSubmission.version > 1 ? (
                /* Dual-Version Comparison View */
                <div className="grid grid-cols-2 gap-6">
                  {/* Left: Original/Previous */}
                  <div className="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                    <div className="bg-gray-100 px-4 py-3 border-b border-gray-200 flex items-center gap-2">
                      <History className="w-4 h-4 text-gray-500" />
                      <span className="font-bold text-sm text-gray-700">Original Submission (v{selectedSubmission.version - 1})</span>
                    </div>
                    <div className="p-4 space-y-4">
                      <div className="p-4 bg-red-50 border border-red-100 rounded-lg">
                        <h4 className="text-xs font-bold text-red-800 uppercase mb-2 flex items-center gap-1">
                          <MessageSquare className="w-3 h-3" />
                          Previous Admin Feedback
                        </h4>
                        <p className="text-sm text-red-900 italic">
                          "{selectedSubmission.previous_version_feedback || 'No previous feedback recorded.'}"
                        </p>
                      </div>
                      <div className="aspect-[4/3] bg-gray-200 rounded-lg flex items-center justify-center border-2 border-dashed border-gray-300">
                        <p className="text-xs text-gray-400 font-medium">Previous Version Preview</p>
                      </div>
                    </div>
                  </div>

                  {/* Right: Revised/Current */}
                  <div className="bg-white border border-[#800000] rounded-xl shadow-md overflow-hidden">
                    <div className="bg-[#800000] px-4 py-3 border-b border-[#800000] flex items-center gap-2">
                      <RefreshCw className="w-4 h-4 text-white" />
                      <span className="font-bold text-sm text-white">Revised Submission (v{selectedSubmission.version})</span>
                    </div>
                    <div className="p-4 space-y-4">
                      <div className="p-4 bg-green-50 border border-green-100 rounded-lg">
                        <h4 className="text-xs font-bold text-green-800 uppercase mb-2 flex items-center gap-1">
                          <AlertCircle className="w-3 h-3" />
                          Revision Summary
                        </h4>
                        <p className="text-sm text-green-900">
                          The unit has submitted a new version addressing the previous feedback. Please verify the changes.
                        </p>
                      </div>
                      <div className="aspect-[4/3] bg-white rounded-lg flex items-center justify-center border border-gray-200 shadow-inner">
                        <div className="text-center">
                          <FileText className="w-12 h-12 text-gray-300 mx-auto mb-2" />
                          <p className="text-xs text-gray-500 font-medium">{selectedSubmission.doc_type}_Revised_v{selectedSubmission.version}.pdf</p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              ) : (
                /* Single-Pane Preview for v1 */
                <div className="max-w-3xl mx-auto bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                  <div className="bg-gray-100 px-4 py-3 border-b border-gray-200 flex items-center gap-2">
                    <FileText className="w-4 h-4 text-gray-500" />
                    <span className="font-bold text-sm text-gray-700">Document Preview</span>
                  </div>
                  <div className="p-8 text-center">
                    <div className="w-20 h-20 bg-blue-50 text-blue-500 rounded-full flex items-center justify-center mx-auto mb-4">
                      <FileText className="w-10 h-10" />
                    </div>
                    <h3 className="text-lg font-bold text-gray-900 mb-1">{selectedSubmission.doc_type} Initial Submission</h3>
                    <p className="text-sm text-gray-500 mb-6">First version of the document for the current period.</p>
                    <div className="aspect-video bg-gray-50 border-2 border-dashed border-gray-200 rounded-lg flex items-center justify-center">
                      <p className="text-sm text-gray-400">PDF Preview Placeholder</p>
                    </div>
                  </div>
                </div>
              )}

              {/* Action Center */}
              <div className="max-w-3xl mx-auto bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden">
                <div className="p-4 border-b border-gray-100 bg-gray-50">
                  <h3 className="font-bold text-gray-800 flex items-center gap-2">
                    <MessageSquare className="w-4 h-4 text-[#800000]" />
                    Contextual Action Center
                  </h3>
                </div>
                <div className="p-4 space-y-4">
                  <div>
                    <label className="block text-xs font-bold text-gray-500 uppercase mb-2">
                      Reviewer Feedback (Required for Revisions)
                    </label>
                    <textarea
                      className="w-full min-h-[120px] p-3 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#800000] focus:border-transparent outline-none transition-all"
                      placeholder="Enter specific, timestamped feedback here..."
                      value={feedback}
                      onChange={(e) => setFeedback(e.target.value)}
                    />
                  </div>
                  <div className="flex gap-3">
                    <button
                      onClick={() => handleAction('REVISION')}
                      disabled={isProcessing}
                      className="flex-1 flex items-center justify-center gap-2 py-3 border-2 border-amber-500 text-amber-600 font-bold rounded-lg hover:bg-amber-50 transition-colors disabled:opacity-50"
                    >
                      <RefreshCw className={`w-4 h-4 ${isProcessing ? 'animate-spin' : ''}`} />
                      Return for Revision
                    </button>
                    <button
                      onClick={() => handleAction('APPROVE')}
                      disabled={isProcessing}
                      className="flex-1 flex items-center justify-center gap-2 py-3 bg-green-600 text-white font-bold rounded-lg hover:bg-green-700 transition-colors shadow-md disabled:opacity-50"
                    >
                      <CheckCircle className="w-4 h-4" />
                      Approve Document
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </>
        ) : (
          /* Empty State */
          <div className="flex-1 flex flex-col items-center justify-center text-gray-400 bg-gray-50">
            <div className="w-20 h-20 bg-white rounded-full shadow-sm flex items-center justify-center mb-4 border border-gray-100">
              <Search className="w-10 h-10 text-gray-200" />
            </div>
            <h2 className="text-xl font-bold text-gray-900 mb-1">Select a Submission</h2>
            <p className="text-sm max-w-xs text-center">
              Choose a document from the queue on the left to begin the quality audit process.
            </p>
          </div>
        )}
      </div>
    </div>
  );
};

export default ReviewSubmissions;
