"""Dependency-free hashed bag-of-words vectors, stored as JSON for later reuse."""
import hashlib, json, math, re

DIM = 512


def vectorize(text):
    v = [0.0] * DIM
    for w in re.findall(r"[\w']+", text.lower()):
        h = int(hashlib.md5(w.encode()).hexdigest(), 16)
        v[h % DIM] += 1 if (h >> 64) & 1 else -1
    norm = math.sqrt(sum(x * x for x in v)) or 1.0
    return [round(x / norm, 5) for x in v]


def cosine(a, b):
    return sum(x * y for x, y in zip(a, b))


class VectorStore:
    def __init__(self, path):
        self.path = path
        try:
            with open(path) as f:
                self.items = json.load(f)
        except FileNotFoundError:
            self.items = []

    def add(self, text, meta=None):
        self.items.append({"text": text, "meta": meta or {}, "vec": vectorize(text)})

    def search(self, text, k=3):
        q = vectorize(text)
        return sorted(self.items, key=lambda i: -cosine(q, i["vec"]))[:k]

    def save(self):
        with open(self.path, "w") as f:
            json.dump(self.items, f)
