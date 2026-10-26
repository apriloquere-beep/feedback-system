from __future__ import annotations

import csv
import hashlib
import random
import sys
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MASTER = ROOT / "data" / "labeled" / "sfms_sentiment_master.csv"
OUTPUT = ROOT / "data" / "splits"
SEED = 20260913


def stable_key(row: dict[str, str]) -> tuple[str, str, str]:
    return row["label"], row["language_group"], row["topic"]


def main() -> int:
    if not MASTER.exists():
        print(f"ERROR: master dataset not found: {MASTER}")
        return 1

    with MASTER.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        fieldnames = reader.fieldnames or []
        rows = list(reader)

    # Keep every duplicate/paraphrase group wholly inside one split.
    groups: dict[str, list[dict[str, str]]] = defaultdict(list)
    for row in rows:
        group = row.get("duplicate_group", "").strip()
        if not group:
            group = hashlib.sha256(row["text"].casefold().encode("utf-8")).hexdigest()
        groups[group].append(row)

    rng = random.Random(SEED)
    splits: dict[str, list[dict[str, str]]] = {"train": [], "validation": [], "test": []}
    by_label: dict[str, list[list[dict[str, str]]]] = defaultdict(list)
    for grouped_rows in groups.values():
        by_label[grouped_rows[0]["label"]].append(grouped_rows)

    # Stratify exactly by label. Sorting by language/topic before a seeded
    # shuffle keeps the procedure reproducible while avoiding source order.
    for label, label_groups in sorted(by_label.items()):
        label_groups.sort(key=lambda group: stable_key(group[0]))
        rng.shuffle(label_groups)
        row_count = sum(len(group) for group in label_groups)
        train_target = round(row_count * 0.70)
        validation_target = round(row_count * 0.15)
        assigned = {"train": 0, "validation": 0, "test": 0}
        for grouped_rows in label_groups:
            if assigned["train"] < train_target:
                destination = "train"
            elif assigned["validation"] < validation_target:
                destination = "validation"
            else:
                destination = "test"
            splits[destination].extend(grouped_rows)
            assigned[destination] += len(grouped_rows)

    OUTPUT.mkdir(parents=True, exist_ok=True)
    for name, split_rows in splits.items():
        rng.shuffle(split_rows)
        path = OUTPUT / f"{name}.csv"
        with path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=fieldnames)
            writer.writeheader()
            writer.writerows(split_rows)
        print(f"{name}: {len(split_rows)} rows; labels={dict(Counter(r['label'] for r in split_rows))}")

    assigned = sum(len(values) for values in splits.values())
    if assigned != len(rows):
        print(f"ERROR: assigned {assigned} of {len(rows)} rows")
        return 1
    print(f"Created reproducible group-safe splits in {OUTPUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
