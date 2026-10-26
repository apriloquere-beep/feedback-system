"""Train and evaluate the first SFMS TF-IDF + Logistic Regression model.

The script trains candidate configurations exclusively on train.csv, uses
validation.csv to select the final configuration, and evaluates test.csv once
after model selection. It never rewrites any dataset file.
"""

from __future__ import annotations

import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
import sklearn
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (
    accuracy_score,
    classification_report,
    confusion_matrix,
    precision_recall_fscore_support,
)
from sklearn.pipeline import Pipeline


PROJECT_DIR = Path(__file__).resolve().parents[1]
DATA_DIR = PROJECT_DIR / "data" / "splits"
MODELS_DIR = PROJECT_DIR / "models"
REPORTS_DIR = PROJECT_DIR / "reports"

TRAIN_PATH = DATA_DIR / "train.csv"
VALIDATION_PATH = DATA_DIR / "validation.csv"
TEST_PATH = DATA_DIR / "test.csv"

# Keep this order in all human-facing reports and probability columns.
LABELS = ["Positive", "Negative", "Neutral"]
RANDOM_STATE = 42


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def load_split(path: Path) -> pd.DataFrame:
    frame = pd.read_csv(path)
    missing = {"text", "label"} - set(frame.columns)
    if missing:
        raise ValueError(f"{path.name} is missing required columns: {sorted(missing)}")
    if frame.empty:
        raise ValueError(f"{path.name} contains no samples")
    if frame["text"].isna().any() or frame["label"].isna().any():
        raise ValueError(f"{path.name} contains blank text or label values")

    unexpected = sorted(set(frame["label"]) - set(LABELS))
    if unexpected:
        raise ValueError(f"{path.name} contains unsupported labels: {unexpected}")

    frame = frame.copy()
    frame["text"] = frame["text"].astype(str)
    return frame


def build_pipeline(c_value: float, min_df: int) -> Pipeline:
    # Word-level Unicode tokenization preserves English, Bisaya, Tagalog, and
    # code-switched vocabulary. Apostrophes/hyphens remain inside tokens.
    vectorizer = TfidfVectorizer(
        lowercase=True,
        strip_accents=None,
        analyzer="word",
        token_pattern=r"(?u)\b\w[\w'-]*\b",
        ngram_range=(1, 2),
        min_df=min_df,
        max_df=1.0,
        sublinear_tf=True,
        norm="l2",
    )
    classifier = LogisticRegression(
        C=c_value,
        solver="lbfgs",
        max_iter=2000,
        class_weight=None,
        random_state=RANDOM_STATE,
    )
    return Pipeline([("tfidf", vectorizer), ("classifier", classifier)])


def aggregate_metrics(y_true: pd.Series, y_pred: np.ndarray) -> dict[str, float]:
    macro = precision_recall_fscore_support(
        y_true, y_pred, labels=LABELS, average="macro", zero_division=0
    )
    weighted = precision_recall_fscore_support(
        y_true, y_pred, labels=LABELS, average="weighted", zero_division=0
    )
    return {
        "accuracy": float(accuracy_score(y_true, y_pred)),
        "macro_precision": float(macro[0]),
        "macro_recall": float(macro[1]),
        "macro_f1": float(macro[2]),
        "weighted_precision": float(weighted[0]),
        "weighted_recall": float(weighted[1]),
        "weighted_f1": float(weighted[2]),
    }


def report_table(y_true: pd.Series, y_pred: np.ndarray) -> pd.DataFrame:
    report = classification_report(
        y_true,
        y_pred,
        labels=LABELS,
        output_dict=True,
        zero_division=0,
    )
    rows = []
    for label in LABELS:
        values = report[label]
        rows.append(
            {
                "label": label,
                "precision": values["precision"],
                "recall": values["recall"],
                "f1-score": values["f1-score"],
                "support": int(values["support"]),
            }
        )
    return pd.DataFrame(rows)


def fmt(value: float) -> str:
    return f"{value:.4f}"


def markdown_table(frame: pd.DataFrame, float_digits: int = 4) -> str:
    columns = list(frame.columns)
    lines = [
        "| " + " | ".join(str(column) for column in columns) + " |",
        "| " + " | ".join("---" for _ in columns) + " |",
    ]
    for _, row in frame.iterrows():
        cells = []
        for column in columns:
            value = row[column]
            if isinstance(value, (float, np.floating)):
                cells.append(f"{value:.{float_digits}f}")
            else:
                cells.append(str(value).replace("|", "\\|"))
        lines.append("| " + " | ".join(cells) + " |")
    return "\n".join(lines)


def main() -> None:
    MODELS_DIR.mkdir(parents=True, exist_ok=True)
    REPORTS_DIR.mkdir(parents=True, exist_ok=True)

    input_hashes_before = {
        path.name: sha256(path) for path in (TRAIN_PATH, VALIDATION_PATH, TEST_PATH)
    }
    train = load_split(TRAIN_PATH)
    validation = load_split(VALIDATION_PATH)
    test = load_split(TEST_PATH)

    # Validation-only tuning. Ties prefer the simpler/lower-regularization-cost
    # configuration: lower C first, then the more conservative min_df=2.
    candidates = [
        {"C": c_value, "min_df": min_df}
        for c_value in (0.5, 1.0, 2.0, 4.0)
        for min_df in (2, 1)
    ]
    tuning_rows = []
    fitted_candidates: list[tuple[dict[str, float | int], Pipeline, dict[str, float]]] = []

    for config in candidates:
        pipeline = build_pipeline(float(config["C"]), int(config["min_df"]))
        pipeline.fit(train["text"], train["label"])
        validation_pred = pipeline.predict(validation["text"])
        validation_metrics = aggregate_metrics(validation["label"], validation_pred)
        tuning_rows.append({**config, **validation_metrics})
        fitted_candidates.append((config, pipeline, validation_metrics))

    # No test-set result participates in this selection.
    selected_config, selected_pipeline, selected_validation_metrics = max(
        fitted_candidates,
        key=lambda item: (
            item[2]["macro_f1"],
            item[2]["accuracy"],
            -float(item[0]["C"]),
            int(item[0]["min_df"]),
        ),
    )

    train_pred = selected_pipeline.predict(train["text"])
    validation_pred = selected_pipeline.predict(validation["text"])

    # This is the first and only test prediction, after configuration selection.
    test_pred = selected_pipeline.predict(test["text"])
    test_probabilities = selected_pipeline.predict_proba(test["text"])

    train_metrics = aggregate_metrics(train["label"], train_pred)
    validation_metrics = aggregate_metrics(validation["label"], validation_pred)
    test_metrics = aggregate_metrics(test["label"], test_pred)
    per_class = report_table(test["label"], test_pred)

    classifier = selected_pipeline.named_steps["classifier"]
    vectorizer = selected_pipeline.named_steps["tfidf"]
    class_indices = {label: index for index, label in enumerate(classifier.classes_)}

    prediction_rows = test.copy()
    prediction_rows["actual_label"] = test["label"]
    prediction_rows["predicted_label"] = test_pred
    for label in LABELS:
        prediction_rows[f"{label.lower()}_probability"] = test_probabilities[
            :, class_indices[label]
        ]
    prediction_rows["confidence"] = test_probabilities.max(axis=1)

    confusion = confusion_matrix(test["label"], test_pred, labels=LABELS)
    confusion_frame = pd.DataFrame(
        confusion,
        index=[f"Actual {label}" for label in LABELS],
        columns=[f"Predicted {label}" for label in LABELS],
    )

    tuning_frame = pd.DataFrame(tuning_rows).sort_values(
        by=["macro_f1", "accuracy", "C", "min_df"],
        ascending=[False, False, True, False],
    )

    # Deterministic, confidence-diverse examples: lowest, median, and highest
    # confidence item per actual class (up to nine examples total).
    sample_indices: list[int] = []
    for label in LABELS:
        group = prediction_rows[prediction_rows["actual_label"] == label].sort_values(
            "confidence"
        )
        positions = sorted(set([0, len(group) // 2, len(group) - 1]))
        sample_indices.extend(group.iloc[positions].index.tolist())
    samples = prediction_rows.loc[sample_indices].copy()

    joblib.dump(classifier, MODELS_DIR / "logistic_regression_model.joblib")
    joblib.dump(vectorizer, MODELS_DIR / "tfidf_vectorizer.joblib")
    joblib.dump(selected_pipeline, MODELS_DIR / "sentiment_pipeline.joblib")

    tuning_frame.to_csv(REPORTS_DIR / "validation_tuning.csv", index=False)
    per_class.to_csv(REPORTS_DIR / "classification_report.csv", index=False)
    confusion_frame.to_csv(REPORTS_DIR / "confusion_matrix.csv")
    prediction_rows.to_csv(REPORTS_DIR / "test_predictions.csv", index=False)

    input_hashes_after = {
        path.name: sha256(path) for path in (TRAIN_PATH, VALIDATION_PATH, TEST_PATH)
    }
    if input_hashes_before != input_hashes_after:
        raise RuntimeError("A dataset file changed during training; artifacts are not trusted")

    metadata = {
        "created_at_utc": datetime.now(timezone.utc).isoformat(),
        "model": "TF-IDF Vectorizer + Logistic Regression",
        "selected_configuration": selected_config,
        "classes": LABELS,
        "classifier_classes": classifier.classes_.tolist(),
        "sample_counts": {
            "train": len(train),
            "validation": len(validation),
            "test": len(test),
        },
        "label_counts": {
            split_name: frame["label"].value_counts().reindex(LABELS).fillna(0).astype(int).to_dict()
            for split_name, frame in (
                ("train", train),
                ("validation", validation),
                ("test", test),
            )
        },
        "metrics": {
            "training": train_metrics,
            "validation": validation_metrics,
            "test": test_metrics,
        },
        "vocabulary_size": len(vectorizer.vocabulary_),
        "dataset_sha256": input_hashes_after,
        "versions": {
            "python": __import__("sys").version.split()[0],
            "scikit_learn": sklearn.__version__,
            "pandas": pd.__version__,
            "numpy": np.__version__,
            "joblib": joblib.__version__,
        },
    }
    (MODELS_DIR / "model_metadata.json").write_text(
        json.dumps(metadata, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    (REPORTS_DIR / "evaluation_metrics.json").write_text(
        json.dumps(metadata, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )

    sample_display = samples[
        [
            "text",
            "actual_label",
            "predicted_label",
            "positive_probability",
            "negative_probability",
            "neutral_probability",
            "confidence",
        ]
    ].rename(
        columns={
            "text": "Text",
            "actual_label": "Actual label",
            "predicted_label": "Predicted label",
            "positive_probability": "Positive probability",
            "negative_probability": "Negative probability",
            "neutral_probability": "Neutral probability",
            "confidence": "Confidence",
        }
    )

    report_md = f"""# SFMS Sentiment Model Training Report

Generated: {metadata['created_at_utc']}

## Dataset information

- Source files: `data/splits/train.csv`, `validation.csv`, and `test.csv`
- Training samples: {len(train)}
- Validation samples: {len(validation)}
- Test samples: {len(test)}
- Classes: Positive, Negative, Neutral
- Training used only `train.csv`; validation selected the configuration; test was evaluated only after selection.
- Input SHA-256 hashes are recorded in `models/model_metadata.json`.

## Preprocessing

- Unicode-aware word tokenization suitable for English, Bisaya, Tagalog, and code-switched feedback
- Lowercase normalization
- Unigrams and bigrams
- Apostrophes and hyphens retained within tokens
- Sublinear term frequency and L2 normalization
- No stemming, translation, rule-based sentiment scoring, or external model/API

## Model and validation selection

- Model: multinomial Logistic Regression (`lbfgs`, maximum 2,000 iterations)
- Candidate `C` values: 0.5, 1.0, 2.0, 4.0
- Candidate `min_df` values: 1, 2
- Selection criterion: validation macro F1, then validation accuracy; ties favor the simpler lower-`C` configuration and `min_df=2`
- Selected configuration: `C={selected_config['C']}`, `min_df={selected_config['min_df']}`
- TF-IDF vocabulary size: {len(vectorizer.vocabulary_)}

Full tuning results are in `reports/validation_tuning.csv`.

## Training results

- Accuracy: {fmt(train_metrics['accuracy'])}
- Macro precision: {fmt(train_metrics['macro_precision'])}
- Macro recall: {fmt(train_metrics['macro_recall'])}
- Macro F1-score: {fmt(train_metrics['macro_f1'])}

## Validation results

- Accuracy: {fmt(validation_metrics['accuracy'])}
- Macro precision: {fmt(validation_metrics['macro_precision'])}
- Macro recall: {fmt(validation_metrics['macro_recall'])}
- Macro F1-score: {fmt(validation_metrics['macro_f1'])}

## Final test results

- Accuracy: {fmt(test_metrics['accuracy'])}
- Macro precision: {fmt(test_metrics['macro_precision'])}
- Macro recall: {fmt(test_metrics['macro_recall'])}
- Macro F1-score: {fmt(test_metrics['macro_f1'])}
- Weighted precision: {fmt(test_metrics['weighted_precision'])}
- Weighted recall: {fmt(test_metrics['weighted_recall'])}
- Weighted F1-score: {fmt(test_metrics['weighted_f1'])}

## Per-class test performance

{markdown_table(per_class)}

## Confusion matrix

Rows are actual labels and columns are predicted labels.

{markdown_table(confusion_frame.reset_index().rename(columns={'index': 'Actual / Predicted'}), 0)}

## Probability and confidence examples

Confidence is the highest of the three predicted class probabilities. It is reported separately from the predicted sentiment category.

{markdown_table(sample_display)}

All test predictions and probabilities are in `reports/test_predictions.csv`.

## Saved artifacts

- `models/logistic_regression_model.joblib`
- `models/tfidf_vectorizer.joblib`
- `models/sentiment_pipeline.joblib`
- `models/model_metadata.json`

The standalone vectorizer and classifier can be loaded sequentially by a future Python prediction service. The pipeline combines both steps for safer one-call prediction. Artifact loading should use compatible Python/scikit-learn versions and only trusted files.

## Limitations

- The dataset is synthetic and marked **Pending human review**; the metrics do not establish real-world clinical or deployment performance.
- Synthetic phrasing may make the evaluation easier than feedback written by actual patients and visitors.
- Coverage of dialect, spelling variation, sarcasm, implicit sentiment, rare healthcare terms, and novel code-switching is limited by the dataset.
- Logistic Regression with word n-grams may miss meaning that depends on long context or unfamiliar wording.
- Probability confidence is available but has not been formally calibrated. A future confidence threshold must be chosen and validated against human-reviewed, representative feedback.
- The model's sentiment categories are Positive, Negative, and Neutral.
"""
    (REPORTS_DIR / "training_report.md").write_text(report_md, encoding="utf-8")

    print("Selected configuration:", selected_config)
    print(f"Training samples: {len(train)} | accuracy: {fmt(train_metrics['accuracy'])}")
    print(
        f"Validation samples: {len(validation)} | accuracy: {fmt(validation_metrics['accuracy'])} "
        f"| macro F1: {fmt(validation_metrics['macro_f1'])}"
    )
    print(
        f"Test samples: {len(test)} | accuracy: {fmt(test_metrics['accuracy'])} "
        f"| macro F1: {fmt(test_metrics['macro_f1'])}"
    )
    print("\nTest classification report:")
    print(
        classification_report(
            test["label"], test_pred, labels=LABELS, digits=4, zero_division=0
        )
    )
    print("Test confusion matrix (label order: Positive, Negative, Neutral):")
    print(confusion)
    print("\nProbability/confidence examples:")
    with pd.option_context("display.max_colwidth", 100, "display.width", 220):
        print(sample_display.to_string(index=False, float_format=lambda value: f"{value:.4f}"))


if __name__ == "__main__":
    main()
