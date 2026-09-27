/**
 * Caption editor toolbar (Bold/Italic/Emoji + counter) for the content form.
 *
 * Instagram, Facebook, X, LinkedIn, TikTok, and Threads all render captions
 * as PLAIN TEXT — none of them support HTML or markdown. So "Bold"/"Italic"
 * here don't produce markup; they swap the selected letters for lookalike
 * Unicode Mathematical Alphanumeric Symbols, which every platform displays
 * as visually bold/italic because it's just a different character, not a
 * style. This is the same trick Buffer's own composer and most social
 * schedulers use. Output stays a plain string, so it's safe to store in
 * `caption` and send straight to Buffer.
 */

const BOLD_UPPER_BASE = 0x1d400; // 𝐀
const BOLD_LOWER_BASE = 0x1d41a; // 𝐚
const BOLD_DIGIT_BASE = 0x1d7ce; // 𝟎
const ITALIC_UPPER_BASE = 0x1d434; // 𝐴
const ITALIC_LOWER_BASE = 0x1d44e; // 𝑎
const ITALIC_H = 0x210e; // ℎ — U+1D455 is unassigned, so italic "h" uses this compatibility codepoint instead.

function styledChar(ch, style) {
    const code = ch.codePointAt(0);

    if (style === 'bold') {
        if (ch >= 'A' && ch <= 'Z') return String.fromCodePoint(BOLD_UPPER_BASE + (code - 65));
        if (ch >= 'a' && ch <= 'z') return String.fromCodePoint(BOLD_LOWER_BASE + (code - 97));
        if (ch >= '0' && ch <= '9') return String.fromCodePoint(BOLD_DIGIT_BASE + (code - 48));

        return ch;
    }

    if (style === 'italic') {
        if (ch === 'h') return String.fromCodePoint(ITALIC_H);
        if (ch >= 'A' && ch <= 'Z') return String.fromCodePoint(ITALIC_UPPER_BASE + (code - 65));
        if (ch >= 'a' && ch <= 'z') return String.fromCodePoint(ITALIC_LOWER_BASE + (code - 97));

        return ch; // Unicode has no italic digit variants.
    }

    return ch;
}

// styled character -> plain character, built once, used to detect/undo styling.
const REVERSE = { bold: {}, italic: {} };

for (let i = 0; i < 26; i++) {
    const upper = String.fromCharCode(65 + i);
    const lower = String.fromCharCode(97 + i);
    REVERSE.bold[styledChar(upper, 'bold')] = upper;
    REVERSE.bold[styledChar(lower, 'bold')] = lower;
    REVERSE.italic[styledChar(upper, 'italic')] = upper;
    REVERSE.italic[styledChar(lower, 'italic')] = lower;
}
for (let i = 0; i < 10; i++) {
    const digit = String.fromCharCode(48 + i);
    REVERSE.bold[styledChar(digit, 'bold')] = digit;
}
REVERSE.italic[String.fromCodePoint(ITALIC_H)] = 'h';

/** Toggle: if the selection already contains styled characters, revert those; otherwise style everything styleable. */
function toggleUnicodeStyle(text, style) {
    const chars = Array.from(text); // codepoint-aware — bold/italic letters are surrogate pairs.
    const alreadyStyled = chars.some((c) => REVERSE[style][c] !== undefined);

    return chars.map((c) => (alreadyStyled ? (REVERSE[style][c] ?? c) : styledChar(c, style))).join('');
}

const EMOJI_GROUPS = [
    { label: 'Senyum', items: ['😀', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '😉', '😊', '😍', '🥰', '😘', '😜', '🤔', '😴', '😭', '😢', '😡', '🥳'] },
    { label: 'Tangan & Orang', items: ['👍', '👎', '👏', '🙏', '💪', '🤝', '👋', '✌️', '🤞', '👌', '🙌', '🫶'] },
    { label: 'Hati', items: ['❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '💕', '💖', '💯'] },
    { label: 'Simbol', items: ['🔥', '✨', '🎉', '📢', '📌', '✅', '⏰', '📅', '🎯', '💡', '🚀', '⭐'] },
];

document.addEventListener('alpine:init', () => {
    window.Alpine.data('captionEditor', (limit = null, limitLabel = null) => ({
        limit,
        limitLabel,
        length: 0,
        emojiOpen: false,
        emojiGroups: EMOJI_GROUPS,

        init() {
            this.length = this.countChars(this.$refs.captionInput.value);
            this.$refs.captionInput.addEventListener('input', () => {
                this.length = this.countChars(this.$refs.captionInput.value);
            });
        },

        countChars(text) {
            return Array.from(text).length; // codepoint count — doesn't double-count bold/italic surrogate pairs.
        },

        get counterLabel() {
            return this.limit
                ? `${this.length} / ${this.limit} karakter (${this.limitLabel})`
                : `${this.length} karakter`;
        },

        get overLimit() {
            return this.limit !== null && this.length > this.limit;
        },

        insertEmoji(emoji) {
            const el = this.$refs.captionInput;
            const start = el.selectionStart ?? el.value.length;
            const end = el.selectionEnd ?? el.value.length;

            el.value = el.value.slice(0, start) + emoji + el.value.slice(end);
            el.focus();
            el.setSelectionRange(start + emoji.length, start + emoji.length);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            this.emojiOpen = false;
        },

        applyStyle(style) {
            const el = this.$refs.captionInput;
            const start = el.selectionStart;
            const end = el.selectionEnd;

            if (start === end) return; // nothing selected — no-op, keep it obvious what got styled.

            const converted = toggleUnicodeStyle(el.value.slice(start, end), style);
            el.value = el.value.slice(0, start) + converted + el.value.slice(end);
            el.focus();
            el.setSelectionRange(start, start + converted.length);
            el.dispatchEvent(new Event('input', { bubbles: true }));
        },
    }));
});
