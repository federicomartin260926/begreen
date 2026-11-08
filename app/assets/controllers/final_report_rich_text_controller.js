import { Controller } from '@hotwired/stimulus';

// Minimal native editor based on CRM's contenteditable approach. No external package.
export default class extends Controller {
  connect() {
    this.source = this.element.querySelector('[data-rich-source]');
    this.surface = this.element.querySelector('[data-rich-surface]');
    if (!this.source || !this.surface) return;
    this.initialPlainOrHtml = this.source.value;
    this.setContent(this.source.value, false);
    this.surface.hidden = false;
    this.source.classList.add('d-none');
    this.element.querySelectorAll('[data-rich-command]').forEach((button) => {
      button.addEventListener('mousedown', (event) => event.preventDefault());
    });
    this.surface.addEventListener('input', () => this.sync());
    this.surface.addEventListener('blur', () => this.sync());
    this.surface.addEventListener('paste', (event) => {
      event.preventDefault();
      document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') || '');
      this.sync();
    });
    this.source.addEventListener('rich:set', () => this.setContent(this.source.value, false));
  }

  format(event) {
    event.preventDefault();
    const command = event.currentTarget.dataset.richCommand;
    this.surface.focus();
    if (command === 'ul') document.execCommand('insertUnorderedList');
    else if (command === 'ol') document.execCommand('insertOrderedList');
    else if (['bold', 'italic'].includes(command)) document.execCommand(command);
    this.sync();
  }

  setContent(value, notify = false) {
    const content = value || '';
    if (/<\s*\/?\s*[a-z][a-z0-9]*\b/i.test(content)) {
      this.surface.innerHTML = this.sanitize(content);
    } else {
      this.surface.textContent = content;
    }
    if (notify) this.sync();
  }

  sync() {
    if (this.surface.hidden) return;
    const value = this.sanitize(this.surface.innerHTML);
    if (value === this.source.value) return;
    this.source.value = value;
    this.source.dispatchEvent(new Event('input', { bubbles: true }));
  }

  sanitize(html) {
    const parsed = new DOMParser().parseFromString(`<div>${html || ''}</div>`, 'text/html');
    const allowed = new Set(['P', 'BR', 'STRONG', 'EM', 'UL', 'OL', 'LI']);
    const blocked = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'TEMPLATE']);
    const copy = (node, doc) => {
      if (node.nodeType === Node.TEXT_NODE) return doc.createTextNode(node.nodeValue || '');
      if (node.nodeType !== Node.ELEMENT_NODE || blocked.has(node.tagName)) return null;
      const tag = ({ B: 'STRONG', I: 'EM', DIV: 'P' })[node.tagName] || node.tagName;
      const element = allowed.has(tag) ? doc.createElement(tag.toLowerCase()) : doc.createDocumentFragment();
      Array.from(node.childNodes).forEach((child) => {
        const clean = copy(child, doc);
        if (clean) element.appendChild(clean);
      });
      return element;
    };
    const doc = document.implementation.createHTMLDocument('');
    const output = doc.createElement('div');
    Array.from(parsed.body.firstElementChild?.childNodes || []).forEach((node) => {
      const clean = copy(node, doc);
      if (clean) output.appendChild(clean);
    });
    return output.innerHTML.trim();
  }
}
