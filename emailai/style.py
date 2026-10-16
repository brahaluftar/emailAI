"""Learn writing style (signature, greeting, closing, tone) from sent emails."""
import re
from collections import Counter

GREETING_RE = re.compile(r"^(hi|hello|dear|hey|good (morning|afternoon|evening)|ciao|buongiorno)\b[^\n]*", re.I)
CLOSING_RE = re.compile(r"^(best regards|kind regards|regards|best|thanks|thank you|cheers|sincerely|cordiali saluti|saluti|grazie)\b[,.!]*$", re.I)


def split_body(body):
    """Return (message, closing, signature) for a sent email body."""
    lines = [l.rstrip() for l in body.strip().splitlines()]
    lines = [l for i, l in enumerate(lines) if not l.startswith(">")]
    for i in range(len(lines) - 1, -1, -1):
        if CLOSING_RE.match(lines[i].strip()):
            sig = "\n".join(lines[i + 1:]).strip()
            return "\n".join(lines[:i]).strip(), lines[i].strip(), sig
    return "\n".join(lines).strip(), "", ""


def _tone(messages):
    text = " ".join(messages)
    words = re.findall(r"[\w']+", text.lower())
    sentences = [s for s in re.split(r"[.!?\n]+", text) if s.strip()]
    n = max(len(words), 1)
    return {
        "avg_sentence_words": round(len(words) / max(len(sentences), 1), 1),
        "exclamations_per_100_words": round(100 * text.count("!") / n, 2),
        "contractions_per_100_words": round(100 * len(re.findall(r"\w'\w", text)) / n, 2),
        "formal": bool(re.search(r"\b(dear|sincerely|kind regards|please find)\b", text, re.I)),
        "uses_emoji": bool(re.search("[\U0001F300-\U0001FAFF\u2600-\u27BF]", text)),
    }


def learn_style(sent_bodies):
    """sent_bodies: list of bodies, newest first. Signature comes from the latest emails."""
    parts = [split_body(b) for b in sent_bodies if b and b.strip()]
    msgs = [p[0] for p in parts]
    greetings = Counter()
    for m in msgs:
        first = m.splitlines()[0] if m else ""
        if GREETING_RE.match(first):
            greetings[re.sub(r"\b[A-Z][a-z]+\b(?=[,!]*$)", "{name}", first.strip())] += 1
    closings = Counter(p[1] for p in parts if p[1])
    sigs = [p[2] for p in parts[:5] if p[2]]  # most recent emails
    sig_counts = Counter(sigs)
    signature = sig_counts.most_common(1)[0][0] if sig_counts else ""
    if sigs and sig_counts[sigs[0]] >= 1 and sigs[0] != signature and sig_counts[signature] < 2:
        signature = sigs[0]
    return {
        "greeting": greetings.most_common(1)[0][0] if greetings else "Hi {name},",
        "closing": closings.most_common(1)[0][0] if closings else "Best regards,",
        "signature": signature,
        "tone": _tone(msgs),
        "samples": msgs[:20],
        "sample_count": len(msgs),
    }
