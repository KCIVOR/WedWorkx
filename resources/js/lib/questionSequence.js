/** Answer keys, rather than a cached step number, determine interview progress. */
export function firstUnansweredIndex(questions, answers = {}) {
    const index = questions.findIndex((question) => {
        const fields = question.fields ?? [question.key];
        // Specialized widgets allow blank values under the server's existing field rules.
        const required = (question.required === true || question.optional === false)
            && !['services', 'areas', 'media'].includes(question.type);
        return fields.some((field) => {
            const value = answers?.[field];
            return value === undefined || (required && (value === null || value === '' || (Array.isArray(value) && value.length === 0)));
        });
    });
    return index === -1 ? questions.length : index;
}

export function resumeQuestionIndex(questions, draft) {
    const firstMissing = firstUnansweredIndex(questions, draft?.answers);
    // Text inputs may persist text before submission. Keep that question open on reload.
    const current = questions.findIndex((question) => question.key === draft?.currentKey);
    if (current < 0 && draft && !Object.prototype.hasOwnProperty.call(draft, 'currentKey')
        && draft.phase === 'chat' && Number.isInteger(draft.idx)) {
        // Old drafts can contain typed-but-unsubmitted text. Their index may move us back,
        // but it must never move us past the earliest missing answer after a reorder.
        return Math.min(firstMissing, Math.max(0, Math.min(draft.idx, questions.length)));
    }
    return current < 0 ? firstMissing : Math.min(current, firstMissing);
}
