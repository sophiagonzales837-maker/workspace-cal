<style>
    #chc-notepad-panel {
        position: fixed;
        top: 80px;
        right: 20px;
        width: 400px;
        height: 550px;
        max-width: calc(100vw - 40px);
        max-height: calc(100vh - 100px);
        background: rgba(15, 23, 42, 0.98);
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.8);
        border: 1px solid #3b82f6;
        z-index: 999995;
        display: none;
        flex-direction: column;
        overflow: hidden;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        backdrop-filter: blur(10px);
    }

    .np-header {
        background: #0A2540;
        padding: 12px 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        cursor: move;
        touch-action: none;
    }

    .np-title-text {
        color: #38bdf8;
        font-size: 14px;
        font-weight: bold;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .np-list {
        display: none;
        flex: 1;
        overflow-y: auto;
        background: #0f172a;
        flex-direction: column;
    }

    .np-item {
        padding: 12px 15px;
        border-bottom: 1px solid #1e293b;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }

    .np-item:hover {
        background: #1e293b;
    }

    .np-item-title {
        min-width: 0;
        font-weight: bold;
        color: #f8fafc;
        font-size: 14px;
        text-overflow: ellipsis;
        overflow: hidden;
        white-space: nowrap;
    }

    .np-item-date {
        color: #94a3b8;
        font-size: 10px;
        display: block;
        margin-top: 4px;
    }

    .np-editor {
        display: flex;
        flex: 1;
        flex-direction: column;
        min-height: 0;
        background: #0f172a;
    }

    .np-title-input {
        border: none;
        border-bottom: 1px solid #1e293b;
        background: #0b1220;
        color: #f8fafc;
        padding: 15px;
        font-weight: bold;
        font-size: 15px;
        outline: none;
    }

    .np-textarea {
        flex: 1;
        min-height: 0;
        border: none;
        padding: 15px;
        resize: none;
        outline: none;
        font-size: 14px;
        line-height: 1.6;
        color: #e2e8f0;
        background: transparent;
        border-color: #334155;
    }

    .np-save-status {
        padding: 8px 15px;
        font-size: 10px;
        text-align: right;
        background: #0b1220;
        border-top: 1px solid #1e293b;
        color: #94a3b8;
    }

    .np-btn {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 16px;
        transition: 0.2s;
        padding: 0 5px;
    }

    .np-btn:hover {
        transform: scale(1.1);
    }

    @media (max-width: 480px) {
        #chc-notepad-panel {
            right: 12px;
            top: 80px;
            width: calc(100vw - 24px);
            max-width: none;
            height: 550px;
            max-height: calc(100vh - 100px);
        }
    }
</style>

<div id="chc-notepad-panel" aria-label="Personal notes">
    <div class="np-header" id="np-drag-handle">
        <div class="np-title-text"><i class="fas fa-sticky-note"></i> My Notepad</div>
        <div style="display:flex; gap:12px; align-items:center;">
            <button id="np-back-btn" type="button" onclick="showNoteList()" class="np-btn" title="View all notes" style="color:#38bdf8; display:none; font-size:12px;"><i class="fas fa-list"></i> View All</button>
            <button type="button" onclick="createNewNote()" class="np-btn" title="New Note" style="color:#10b981;"><i class="fas fa-plus"></i></button>
            <button type="button" onclick="toggleNotepad()" class="np-btn" title="Close" style="color:#ef4444;"><i class="fas fa-times"></i></button>
        </div>
    </div>

    <div id="np-list-view" class="np-list" aria-live="polite"></div>

    <div id="np-editor-view" class="np-editor">
        <input type="hidden" id="np-current-id" value="0">
        <input type="text" id="np-title" class="np-title-input" placeholder="Untitled Note" aria-label="Note title">
        <textarea id="np-content" class="np-textarea" placeholder="Start typing your notes here..." aria-label="Note content"></textarea>
        <div id="np-status" class="np-save-status" role="status">Ready</div>
    </div>
</div>

<script>
    let npTimer;
    const npApiPath = window.location.pathname.includes('floating_') ? 'shared/notepad_api.php' : '../shared/notepad_api.php';

    function toggleNotepad() {
        const panel = document.getElementById('chc-notepad-panel');
        if (panel.style.display === 'flex') {
            panel.style.display = 'none';
            return;
        }
        panel.style.display = 'flex';
        openMostRecentNote();
    }

    function openMostRecentNote() {
        fetch(`${npApiPath}?action=load`)
            .then(response => response.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Could not load notes');
                if (Array.isArray(data.notes) && data.notes.length > 0) {
                    const note = data.notes[0];
                    openNote(note.id, note.title || 'Untitled Note', note.content || '');
                } else {
                    createNewNote();
                }
            })
            .catch(() => {
                createNewNote();
                document.getElementById('np-status').textContent = 'Could not load saved notes';
            });
    }

    function loadNotesList() {
        fetch(`${npApiPath}?action=load`)
            .then(response => response.json())
            .then(data => {
                const list = document.getElementById('np-list-view');
                list.replaceChildren();
                if (!data.success) throw new Error(data.message || 'Could not load notes');
                if (data.notes.length === 0) {
                    const empty = document.createElement('div');
                    empty.style.cssText = 'padding:20px; text-align:center; color:#94a3b8; font-size:12px;';
                    empty.textContent = 'No notes yet. Click + to create one.';
                    list.appendChild(empty);
                    return;
                }

                data.notes.forEach(note => {
                    const titleText = note.title ? note.title : 'Untitled Note';
                    const date = new Date(String(note.updated_at || '').replace(' ', 'T'));
                    const dateStr = Number.isNaN(date.getTime()) ? '' : date.toLocaleString('en-US', {
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    const item = document.createElement('div');
                    item.className = 'np-item';
                    item.addEventListener('click', () => openNote(note.id, titleText, note.content || ''));

                    const noteInfo = document.createElement('div');
                    noteInfo.style.cssText = 'min-width:0; overflow:hidden;';

                    const title = document.createElement('span');
                    title.className = 'np-item-title';
                    title.style.cssText = 'display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';
                    title.textContent = titleText;
                    noteInfo.appendChild(title);

                    const updated = document.createElement('span');
                    updated.className = 'np-item-date';
                    updated.textContent = dateStr ? 'Last edited: ' + dateStr : 'Last edited';
                    noteInfo.appendChild(updated);
                    item.appendChild(noteInfo);

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.title = 'Delete note';
                    remove.setAttribute('aria-label', 'Delete note');
                    remove.style.cssText = 'background:none; border:none; color:#ef4444; cursor:pointer;';
                    remove.innerHTML = '<i class="fas fa-trash" aria-hidden="true"></i>';
                    remove.addEventListener('click', event => deleteNote(event, note.id));
                    item.appendChild(remove);
                    list.appendChild(item);
                });
            })
            .catch(() => {
                const list = document.getElementById('np-list-view');
                list.replaceChildren();
                const error = document.createElement('div');
                error.style.cssText = 'padding:20px; text-align:center; color:#b91c1c; font-size:12px;';
                error.textContent = 'Notes could not be loaded.';
                list.appendChild(error);
            });
    }

    async function showNoteList() {
        clearTimeout(npTimer);
        await autoSaveNote();
        document.getElementById('np-editor-view').style.display = 'none';
        document.getElementById('np-list-view').style.display = 'flex';
        document.getElementById('np-back-btn').style.display = 'none';
        loadNotesList();
    }

    function openNote(id, title, content) {
        clearTimeout(npTimer);
        document.getElementById('np-current-id').value = id;
        document.getElementById('np-title').value = title || '';
        document.getElementById('np-content').value = content || '';
        document.getElementById('np-list-view').style.display = 'none';
        document.getElementById('np-editor-view').style.display = 'flex';
        document.getElementById('np-back-btn').style.display = 'block';
        document.getElementById('np-status').textContent = 'All changes saved';
    }

    function createNewNote() {
        openNote(0, '', '');
        document.getElementById('np-title').focus();
        document.getElementById('np-status').textContent = 'New Note';
    }

    async function autoSaveNote() {
        const id = document.getElementById('np-current-id').value;
        const title = document.getElementById('np-title').value.trim();
        const content = document.getElementById('np-content').value;
        if (id === '0' && !title && !content.trim()) return false;

        document.getElementById('np-status').textContent = 'Saving...';
        const formData = new URLSearchParams();
        formData.append('action', 'save');
        formData.append('note_id', id);
        formData.append('title', title || 'Untitled Note');
        formData.append('content', content);

        try {
            const response = await fetch(npApiPath, {
                method: 'POST',
                body: formData
            })
            const data = await response.json();
            if (!data.success) throw new Error('Save failed');
            document.getElementById('np-current-id').value = data.note_id;
            document.getElementById('np-status').textContent = 'All changes saved at ' + new Date().toLocaleTimeString();
            return true;
        } catch (error) {
            document.getElementById('np-status').textContent = 'Could not save note';
            return false;
        }
    }

    function deleteNote(event, id) {
        event.stopPropagation();
        if (!confirm('Delete this note?')) return;
        const formData = new URLSearchParams();
        formData.append('action', 'delete');
        formData.append('note_id', id);
        fetch(npApiPath, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) throw new Error('Delete failed');
                loadNotesList();
            })
            .catch(() => alert('Could not delete note.'));
    }

    document.getElementById('np-title').addEventListener('input', () => {
        clearTimeout(npTimer);
        npTimer = setTimeout(autoSaveNote, 1000);
    });
    document.getElementById('np-content').addEventListener('input', () => {
        clearTimeout(npTimer);
        npTimer = setTimeout(autoSaveNote, 1000);
    });

    if (!window.location.pathname.includes('floating_')) {
        const npDragHandle = document.getElementById('np-drag-handle');
        const npPanel = document.getElementById('chc-notepad-panel');
        let npDragOffsetX = 0;
        let npDragOffsetY = 0;
        let isNpDragging = false;

        npDragHandle.addEventListener('pointerdown', event => {
            if (event.target.closest('button')) return;
            const bounds = npPanel.getBoundingClientRect();
            isNpDragging = true;
            npDragOffsetX = event.clientX - bounds.left;
            npDragOffsetY = event.clientY - bounds.top;
            npDragHandle.setPointerCapture(event.pointerId);
        });

        npDragHandle.addEventListener('pointermove', event => {
            if (!isNpDragging) return;
            const left = Math.max(0, Math.min(event.clientX - npDragOffsetX, window.innerWidth - npPanel.offsetWidth));
            const top = Math.max(0, Math.min(event.clientY - npDragOffsetY, window.innerHeight - npPanel.offsetHeight));
            npPanel.style.left = left + 'px';
            npPanel.style.top = top + 'px';
            npPanel.style.right = 'auto';
        });

        npDragHandle.addEventListener('pointerup', () => {
            isNpDragging = false;
        });
        npDragHandle.addEventListener('pointercancel', () => {
            isNpDragging = false;
        });
    }
</script>