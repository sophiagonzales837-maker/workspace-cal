(() => {
    const allowedTags = new Set(['br', 'strong', 'b', 'em', 'i', 'span', 'p', 'div', 'ul', 'ol', 'li']);

    window.renderSafeReport = (target, value) => {
        const parsed = new DOMParser().parseFromString(String(value ?? ''), 'text/html');
        const fragment = document.createDocumentFragment();

        const copySafeNode = (parent, node) => {
            if (node.nodeType === Node.TEXT_NODE) {
                parent.appendChild(document.createTextNode(node.nodeValue || ''));
                return;
            }
            if (node.nodeType !== Node.ELEMENT_NODE) return;

            const tagName = node.tagName.toLowerCase();
            const allowed = allowedTags.has(tagName);
            const safeNode = allowed ? document.createElement(tagName) : parent;
            if (allowed && tagName === 'span') {
                const color = node.style.color;
                const fontWeight = node.style.fontWeight;
                if (/^(#[0-9a-f]{3,8}|rgba?\([0-9.,%\s]+\))$/i.test(color)) safeNode.style.color = color;
                if (fontWeight === 'bold' || fontWeight === '600' || fontWeight === '700') safeNode.style.fontWeight = fontWeight;
            }
            if (allowed) parent.appendChild(safeNode);
            Array.from(node.childNodes).forEach((child) => copySafeNode(safeNode, child));
        };

        Array.from(parsed.body.childNodes).forEach((node) => copySafeNode(fragment, node));
        target.replaceChildren(fragment);
    };
})();
