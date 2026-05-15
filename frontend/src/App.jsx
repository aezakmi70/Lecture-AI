import { useState, useRef, useEffect } from 'react'
import './index.css'
import { jsPDF } from 'jspdf'

function App() {
  const [file, setFile] = useState(null)
  
  // Load state from localStorage on init
  const [uploadStatus, setUploadStatus] = useState(() => {
    const saved = localStorage.getItem('uploadStatus');
    return saved ? JSON.parse(saved) : null;
  })
  
  const [chatHistory, setChatHistory] = useState(() => {
    const saved = localStorage.getItem('chatHistory');
    return saved ? JSON.parse(saved) : [
      { role: 'ai', content: 'Hello! Please upload a PDF lecture note, then you can ask me questions about it.' }
    ];
  })
  
  const [documentName, setDocumentName] = useState(() => localStorage.getItem('documentName') || null)
  const [documentId, setDocumentId] = useState(() => localStorage.getItem('documentId') || null)
  const [notes, setNotes] = useState(() => {
    const saved = localStorage.getItem('studyNotes')
    return saved ? JSON.parse(saved) : []
  })
  const [noteDraft, setNoteDraft] = useState('')
  const [lastSummary, setLastSummary] = useState(() => localStorage.getItem('lastSummary') || '')
  const [summarySaved, setSummarySaved] = useState(false)

  const [isUploading, setIsUploading] = useState(false)
  const [isSummarizing, setIsSummarizing] = useState(false)
  const [currentQuery, setCurrentQuery] = useState('')
  const [isTyping, setIsTyping] = useState(false)
  
  const fileInputRef = useRef(null)
  const chatEndRef = useRef(null)
  const cursorRef = useRef(null)
  const noteInputRef = useRef(null)

  // Smooth glowing fixed grid effect
  useEffect(() => {
    let mouseX = -1000;
    let mouseY = -1000;
    let currX = -1000;
    let currY = -1000;
    let targetOpacity = 0;
    let currOpacity = 0;
    let idleTimeout;
    let animationFrameId;

    const handleMouseMove = (e) => {
      mouseX = e.clientX;
      mouseY = e.clientY;
      targetOpacity = 1;

      // Reset the disappearance timer whenever the mouse moves
      clearTimeout(idleTimeout);
      idleTimeout = setTimeout(() => {
        targetOpacity = 0;
      }, 2500); // Wait 2.5 seconds of idle time before it starts fading out
    };

    const animate = () => {
      currX += (mouseX - currX) * 0.035; // Decreased for a more noticeable smooth delay
      currY += (mouseY - currY) * 0.035;
      currOpacity += (targetOpacity - currOpacity) * 0.005; // Extremely slow, soft fade transition

      if (cursorRef.current) {
        cursorRef.current.style.setProperty('--mouse-x', `${currX}px`);
        cursorRef.current.style.setProperty('--mouse-y', `${currY}px`);
        cursorRef.current.style.opacity = currOpacity.toFixed(3);
      }
      animationFrameId = requestAnimationFrame(animate);
    };

    window.addEventListener('mousemove', handleMouseMove);
    animate();

    return () => {
      window.removeEventListener('mousemove', handleMouseMove);
      cancelAnimationFrame(animationFrameId);
      clearTimeout(idleTimeout);
    };
  }, []);

  // Save to localStorage whenever these change
  useEffect(() => {
    localStorage.setItem('chatHistory', JSON.stringify(chatHistory));
  }, [chatHistory])

  useEffect(() => {
    if (uploadStatus) {
      localStorage.setItem('uploadStatus', JSON.stringify(uploadStatus));
    }
  }, [uploadStatus])

  useEffect(() => {
    if (documentName) {
      localStorage.setItem('documentName', documentName);
    }
  }, [documentName])

  useEffect(() => {
    if (documentId) {
      localStorage.setItem('documentId', documentId);
    }
  }, [documentId])

  useEffect(() => {
    localStorage.setItem('studyNotes', JSON.stringify(notes));
  }, [notes])

  useEffect(() => {
    if (lastSummary) {
      localStorage.setItem('lastSummary', lastSummary);
    }
  }, [lastSummary])

  // Auto-scroll chat
  useEffect(() => {
    chatEndRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [chatHistory, isTyping])

  const handleFileChange = async (e) => {
    if (e.target.files && e.target.files[0]) {
      const selectedFile = e.target.files[0]
      setFile(selectedFile)
      setUploadStatus(null)
      // Automatically trigger upload
      await processFile(selectedFile)
    }
  }

  const processFile = async (fileToUpload) => {
    setIsUploading(true)
    setUploadStatus(null)
    
    const formData = new FormData()
    formData.append('file', fileToUpload)
    
    try {
      const res = await fetch('/api/ai/upload', {
        method: 'POST',
        body: formData
      })
      const data = await res.json()
      
      if (res.ok) {
        setUploadStatus({ type: 'success', msg: data.message || 'File uploaded and processed successfully!' })
        setDocumentName(fileToUpload.name)
        if (data.document_id) setDocumentId(String(data.document_id))
        if (data.warning) {
          setUploadStatus({ type: 'success', msg: data.warning })
        }
        setChatHistory(prev => [...prev, { role: 'ai', content: `I have processed "${fileToUpload.name}". What would you like to know? You can also ask for a summary.` }])
      } else {
        const errorMsg = data.detail || data.error || (data.errors ? JSON.stringify(data.errors) : null) || 'Failed to process file.'
        setUploadStatus({ type: 'error', msg: errorMsg })
      }
    } catch (err) {
      setUploadStatus({ type: 'error', msg: 'Network error connecting to the API.' })
    } finally {
      setIsUploading(false)
    }
  }

  const handleSummarize = async () => {
    setIsSummarizing(true)
    setIsTyping(true)
    setSummarySaved(false)
    setChatHistory(prev => [...prev, { role: 'user', content: 'Can you summarize the document?' }])
    
    try {
      const res = await fetch('/api/ai/summarize', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ document_id: documentId })
      })
      const data = await res.json()
      
      if (res.ok) {
        const summaryText = data.summary || data.answer || 'Summary generated, but no text was returned.'
        setLastSummary(summaryText)
        setChatHistory(prev => [...prev, { role: 'ai', content: `**Summary:**\n${summaryText}` }])
        setSummarySaved(false)
      } else {
        const errorMsg = typeof data.detail === 'object'
          ? JSON.stringify(data.detail)
          : (data.detail || data.error || 'Could not generate summary.')
        setChatHistory(prev => [...prev, { role: 'ai', content: `Error: ${errorMsg}` }])
      }
    } catch (err) {
      setChatHistory(prev => [...prev, { role: 'ai', content: 'Network error connecting to the API.' }])
    } finally {
      setIsSummarizing(false)
      setIsTyping(false)
    }
  }

  const addStudyNote = (text) => {
    const cleaned = text.trim()
    if (!cleaned) return
    setNotes(prev => [
      { id: Date.now(), text: cleaned, createdAt: new Date().toLocaleString() },
      ...prev
    ])
    setNoteDraft('')
    if (noteInputRef.current) {
      noteInputRef.current.style.height = 'auto'
    }
  }

  const saveSummaryToNotes = () => {
    if (lastSummary) {
      addStudyNote(`Summary:\n${lastSummary}`)
      setSummarySaved(true)
    }
  }

  const adjustNoteHeight = (el) => {
    if (!el) return
    el.style.height = 'auto'
    const max = 220
    const newHeight = Math.min(el.scrollHeight, max)
    el.style.height = `${newHeight}px`
  }

  const handleSend = async (e) => {
    e.preventDefault()
    if (!currentQuery.trim()) return
    
    const query = currentQuery
    setCurrentQuery('')
    setChatHistory(prev => [...prev, { role: 'user', content: query }])
    setIsTyping(true)
    
    try {
      const res = await fetch('/api/ai/ask', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ question: query, document_id: documentId })
      })
      const data = await res.json()
      
      if (res.ok) {
        setChatHistory(prev => [...prev, { role: 'ai', content: data.answer }])
      } else {
        setChatHistory(prev => [...prev, { role: 'ai', content: `Error: ${data.detail || 'Could not process question. Did you upload a document?'}` }])
      }
    } catch (err) {
      setChatHistory(prev => [...prev, { role: 'ai', content: 'Network error connecting to the API.' }])
    } finally {
      setIsTyping(false)
    }
  }

  const renderMessageContent = (content) => {
    return content.split('\n').map((line, index) => {
      let isHeader = false;
      let isBullet = false;
      
      if (line.startsWith('### ')) {
        line = line.substring(4);
        isHeader = true;
      } else if (line.startsWith('## ')) {
        line = line.substring(3);
        isHeader = true;
      } else if (line.trim().startsWith('- ')) {
        isBullet = true;
      }

      const parts = line.split(/(\*\*.*?\*\*)/g).map((part, i) => {
        if (part.startsWith('**') && part.endsWith('**')) {
          return <strong key={i} style={{color: '#e2e8f0'}}>{part.slice(2, -2)}</strong>;
        }
        return part;
      });

      if (isHeader) {
        return <h3 key={index} style={{ margin: '15px 0 8px 0', color: '#818cf8', fontWeight: '600' }}>{parts}</h3>;
      }
      
      if (isBullet) {
        return <div key={index} style={{ paddingLeft: '20px', marginBottom: '6px', color: '#cbd5e1' }}>{parts}</div>;
      }

      if (!line.trim()) return <div key={index} style={{ height: '8px' }}></div>;

      return <div key={index} style={{ marginBottom: '8px', lineHeight: '1.6' }}>{parts}</div>;
    });
  };

  const exportTextToPdf = (text, filename) => {
    try {
      const doc = new jsPDF({ unit: 'pt', format: 'a4' })
      const margin = 40
      const pageWidth = doc.internal.pageSize.getWidth()
      const pageHeight = doc.internal.pageSize.getHeight()
      const maxLineWidth = pageWidth - margin * 2
      doc.setFontSize(12)
      const lines = doc.splitTextToSize(text, maxLineWidth)
      let cursorY = margin
      const lineHeight = 14

      for (let i = 0; i < lines.length; i++) {
        if (cursorY + lineHeight > pageHeight - margin) {
          doc.addPage()
          cursorY = margin
        }
        doc.text(lines[i], margin, cursorY)
        cursorY += lineHeight
      }

      doc.save(filename || `note-${Date.now()}.pdf`)
    } catch (err) {
      console.error('PDF export failed', err)
      alert('Failed to generate PDF. See console for details.')
    }
  }

  return (
    <div className="app-container">
      <div className="fixed-grid" ref={cursorRef}></div>
      <header>
        <div className="logo-container">
          <svg className="logo-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <defs>
              <linearGradient id="logo-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stopColor="#6366f1" />
                <stop offset="100%" stopColor="#a855f7" />
              </linearGradient>
            </defs>
            <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
            <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
            <circle cx="12" cy="8" r="2" fill="url(#logo-gradient)" stroke="none"></circle>
            <path d="M12 10v4"></path>
            <path d="M9 16l3 3 3-3" stroke="url(#logo-gradient)"></path>
          </svg>
          <h1>LectureAI</h1>
        </div>
        <p>Your Intelligent Study Assistant</p>
      </header>

      <div className="main-grid">
        {/* Upload Panel */}
        <div className="glass-panel upload-section">
          <h2>Document Source</h2>
          <div 
            className={`upload-area ${documentName ? 'active' : ''}`}
            onClick={() => fileInputRef.current?.click()}
          >
            <div className="upload-icon">📄</div>
            <p>{isUploading ? "Processing..." : (documentName ? documentName : "Click to select a PDF lecture note")}</p>
            <input 
              type="file" 
              accept=".pdf" 
              ref={fileInputRef} 
              onChange={handleFileChange}
              disabled={isUploading}
              style={{ display: 'none' }}
            />
          </div>

          {uploadStatus && (
            <div className={`status-msg status-${uploadStatus.type}`}>
              {uploadStatus.msg}
            </div>
          )}

          <div style={{marginTop: '2rem'}}>
            <h2>Quick Actions</h2>
            <button 
              className="btn-primary" 
              onClick={handleSummarize}
              disabled={isSummarizing || isTyping || !documentId}
              style={{background: 'linear-gradient(135deg, #10b981, #059669)', marginBottom: '10px'}}
            >
              {isSummarizing ? 'Summarizing...' : 'Summarize Notes'}
            </button>
            <button 
              className="btn-primary" 
              onClick={() => {
                localStorage.removeItem('documentId');
                localStorage.removeItem('documentName');
                localStorage.removeItem('uploadStatus');
                localStorage.removeItem('studyNotes');
                localStorage.removeItem('lastSummary');
                setUploadStatus(null);
                setDocumentName(null);
                setDocumentId(null);
                setNotes([]);
                setLastSummary('');
                setNoteDraft('');
                setChatHistory([{ role: 'ai', content: 'Hello! Please upload a PDF lecture note, then you can ask me questions about it.' }]);
              }}
              style={{background: 'linear-gradient(135deg, #ef4444, #b91c1c)'}}
            >
              Clear Session
            </button>
          </div>

          <div style={{ marginTop: '2rem', textAlign: 'left' }}>
            <h2>Quick Actions</h2>
            <p style={{ color: '#94a3b8', fontSize: '0.92rem' }}>Study notes are available in the right panel for easier access.</p>
          </div>
        </div>
        {/* Chat Panel */}
        <div className="glass-panel chat-section">
          <div className="chat-history">
            {chatHistory.map((msg, idx) => (
              <div key={idx} className={`message ${msg.role}`}>
                <div className="message-label">{msg.role === 'ai' ? 'LectureAI' : 'You'}</div>
                <div className="message-content">{renderMessageContent(msg.content)}</div>
              </div>
            ))}
            {isTyping && (
              <div className="message ai">
                <div className="message-label">LectureAI</div>
                <div className="typing-indicator">
                  <span></span><span></span><span></span>
                </div>
              </div>
            )}
            <div ref={chatEndRef} />
          </div>

          <form className="input-area" onSubmit={handleSend}>
            <input 
              type="text" 
              placeholder="Ask a question about your lecture notes..." 
              value={currentQuery}
              onChange={e => setCurrentQuery(e.target.value)}
              disabled={isTyping}
            />
            <button 
              type="submit" 
              className="btn-send"
              disabled={isTyping || !currentQuery.trim()}
            >
              ➤
            </button>
          </form>
        </div>
        
        {/* Notes Panel */}
        <div className="glass-panel notes-section">
          <h2>Study Notes</h2>
          <textarea
            ref={noteInputRef}
            value={noteDraft}
            onChange={(e) => { setNoteDraft(e.target.value) }}
            onInput={(e) => adjustNoteHeight(e.target)}
            placeholder="Write a quick note, memory trick, or exam reminder..."
            rows={4}
            className="study-note-input"
          />
          <div style={{ display: 'flex', gap: '0.5rem', marginTop: '0.5rem' }}>
            <button className="btn-primary" onClick={() => addStudyNote(noteDraft)} style={{ flex: 1 }}>
              Save Note
            </button>
            <button
              className="btn-primary"
              onClick={saveSummaryToNotes}
              disabled={!lastSummary || summarySaved}
              style={{ background: summarySaved ? 'linear-gradient(135deg, #6b7280, #374151)' : 'linear-gradient(135deg, #0ea5e9, #2563eb)', flex: 1 }}
            >
              {summarySaved ? 'Saved' : 'Save Summary'}
            </button>
          </div>

          <div className="notes-list" style={{ marginTop: '1rem' }}>
            {notes.length === 0 ? (
              <div style={{ color: '#94a3b8', fontSize: '0.92rem' }}>No study notes yet. Add one after reviewing the summary.</div>
            ) : (
              notes.map((note) => (
                <div key={note.id} className="study-note-card">
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.35rem' }}>
                    <div className="study-note-time">{note.createdAt}</div>
                    <div>
                      <button
                        onClick={() => exportTextToPdf(`Saved: ${note.createdAt}\n\n${note.text}`, `note-${note.id}.pdf`)}
                        style={{
                          background: 'transparent',
                          border: '1px solid rgba(255,255,255,0.08)',
                          color: '#cbd5e1',
                          padding: '6px 10px',
                          borderRadius: '10px',
                          cursor: 'pointer',
                          fontSize: '0.78rem'
                        }}
                      >
                        Save as PDF
                      </button>
                    </div>
                  </div>
                  <div className="study-note-text">{note.text}</div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  )
}

export default App
