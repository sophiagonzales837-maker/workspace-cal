<section class="panel" aria-labelledby="saved-quiz-questions-heading">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h2 id="saved-quiz-questions-heading" style="margin:0;">Saved verifier questions</h2>
        <button type="button" class="action-btn" onclick="addQuestionToEnd()">Add Question</button>
    </div>
    <p style="color:#64748b;">Questions load automatically for the selected vertical. Edit the question or its closer application mapping, then save.</p>
    <div id="quiz-editor-status" role="status" style="min-height:20px;color:#0f766e;"></div>
    <div id="quiz-container" aria-live="polite">Loading questions...</div>
    <div class="actions" style="margin-top:16px;">
        <button type="button" class="action-btn" onclick="saveVerticalQuestions()">Save Questions</button>
    </div>
</section>
<style>
    .question-block { display:grid; grid-template-columns:minmax(220px, 2fr) minmax(180px, 1fr) auto; gap:10px; align-items:start; padding:12px; margin:10px 0; border:1px solid #334155; border-radius:8px; background:#111c2e; }
    .question-block textarea { width:100%; min-height:70px; padding:8px; border:1px solid #475569; border-radius:5px; background:#0b1220; color:#f8fafc; box-sizing:border-box; }
    .question-block-actions { display:flex; flex-direction:column; gap:6px; }
    .question-block-actions button { padding:7px 9px; border:0; border-radius:5px; cursor:pointer; white-space:nowrap; }
    @media (max-width:760px) { .question-block { grid-template-columns:1fr; } .question-block-actions { flex-direction:row; flex-wrap:wrap; } }
</style>
<script>
    const quizEditorCsrfToken = <?= json_encode($csrf_token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function escapeQuizHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[character]);
    }

    function createQuestionBlock(question, hiddenKeyword, questionId, vertical) {
        return `
            <div class="question-block" data-question-id="${escapeQuizHtml(questionId)}" data-vertical="${escapeQuizHtml(vertical)}">
                <label>Verifier question
                    <textarea class="quiz-question-text" maxlength="65535">${escapeQuizHtml(question)}</textarea>
                </label>
                <label>Closer application mapping
                    <textarea class="quiz-question-keyword" maxlength="1000" placeholder="Leave blank for no mapping">${escapeQuizHtml(hiddenKeyword)}</textarea>
                </label>
                <div class="question-block-actions">
                    <button type="button" onclick="insertQuestionAfter(this)">Add 1 After</button>
                    <button type="button" onclick="this.closest('.question-block').remove()">Remove</button>
                </div>
            </div>`;
    }

    async function loadVerticalQuestions(vertical) {
        const container = document.getElementById('quiz-container');
        const status = document.getElementById('quiz-editor-status');
        container.replaceChildren();
        status.textContent = 'Loading questions...';
        if (!vertical) {
            status.textContent = 'Choose a vertical to load questions.';
            return;
        }

        try {
            const response = await fetch(`../shared/api_manage_quiz.php?action=get_questions&vertical=${encodeURIComponent(vertical)}`);
            const result = await response.json();
            if (!response.ok || result.status !== 'success' || !Array.isArray(result.questions)) {
                throw new Error(result.message || 'Unable to load questions.');
            }
            if (result.questions.length) {
                container.innerHTML = result.questions.map(item => createQuestionBlock(
                    item.question,
                    item.hidden_keyword,
                    item.id,
                    vertical
                )).join('');
            } else {
                container.innerHTML = '<p class="quiz-empty-state" style="color:#94a3b8;">No saved questions. Add one to begin.</p>';
            }
            status.textContent = '';
        } catch (error) {
            status.textContent = error.message || 'Unable to load questions.';
            status.style.color = '#b91c1c';
        }
    }

    function insertQuestionAfter(buttonElement) {
        const currentBlock = buttonElement.closest('.question-block');
        if (!currentBlock) return;
        currentBlock.insertAdjacentHTML('afterend', createQuestionBlock('', '', '', ''));
    }

    function addQuestionToEnd() {
        const container = document.getElementById('quiz-container');
        const lastBlock = container.querySelector('.question-block:last-of-type');
        const blockHtml = createQuestionBlock('', '', '', document.querySelector('select[name="vertical"]').value);
        if (lastBlock) {
            lastBlock.insertAdjacentHTML('afterend', blockHtml);
        } else {
            container.innerHTML = blockHtml;
        }
    }

    async function saveVerticalQuestions() {
        const vertical = document.querySelector('select[name="vertical"]').value;
        const status = document.getElementById('quiz-editor-status');
        const questions = Array.from(document.querySelectorAll('#quiz-container .question-block')).map(block => ({
            question: block.querySelector('.quiz-question-text').value,
            hidden_keyword: block.querySelector('.quiz-question-keyword').value
        }));
        status.style.color = '#0f766e';
        status.textContent = 'Saving questions...';

        try {
            const response = await fetch('../shared/api_manage_quiz.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_questions',
                    vertical,
                    questions,
                    csrf_token: quizEditorCsrfToken
                })
            });
            const result = await response.json();
            if (!response.ok || result.status !== 'success') {
                throw new Error(result.message || 'Unable to save questions.');
            }
            status.textContent = result.message;
            await loadVerticalQuestions(vertical);
            status.textContent = result.message;
            status.style.color = result.synced ? '#0f766e' : '#b45309';
        } catch (error) {
            status.textContent = error.message || 'Unable to save questions.';
            status.style.color = '#b91c1c';
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const verticalSelect = document.querySelector('select[name="vertical"]');
        if (verticalSelect) loadVerticalQuestions(verticalSelect.value);
    });
</script>
