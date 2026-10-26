# SFMS Sentiment Model Training Report

Generated: 2026-09-13T15:27:17.783747+00:00

## Dataset information

- Source files: `data/splits/train.csv`, `validation.csv`, and `test.csv`
- Training samples: 630
- Validation samples: 135
- Test samples: 135
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
- Selected configuration: `C=0.5`, `min_df=2`
- TF-IDF vocabulary size: 1884

Full tuning results are in `reports/validation_tuning.csv`.

## Training results

- Accuracy: 1.0000
- Macro precision: 1.0000
- Macro recall: 1.0000
- Macro F1-score: 1.0000

## Validation results

- Accuracy: 1.0000
- Macro precision: 1.0000
- Macro recall: 1.0000
- Macro F1-score: 1.0000

## Final test results

- Accuracy: 1.0000
- Macro precision: 1.0000
- Macro recall: 1.0000
- Macro F1-score: 1.0000
- Weighted precision: 1.0000
- Weighted recall: 1.0000
- Weighted F1-score: 1.0000

## Per-class test performance

| label | precision | recall | f1-score | support |
| --- | --- | --- | --- | --- |
| Positive | 1.0000 | 1.0000 | 1.0000 | 45 |
| Negative | 1.0000 | 1.0000 | 1.0000 | 45 |
| Neutral | 1.0000 | 1.0000 | 1.0000 | 45 |

## Confusion matrix

Rows are actual labels and columns are predicted labels.

| Actual / Predicted | Predicted Positive | Predicted Negative | Predicted Neutral |
| --- | --- | --- | --- |
| Actual Positive | 45 | 0 | 0 |
| Actual Negative | 0 | 45 | 0 |
| Actual Neutral | 0 | 0 | 45 |

## Probability and confidence examples

Confidence is the highest of the three predicted class probabilities. It is reported separately from the predicted sentiment category.

| Text | Actual label | Predicted label | Positive probability | Negative probability | Neutral probability | Confidence |
| --- | --- | --- | --- | --- | --- | --- |
| The visit went smoothly and I felt respected; this is what I observed before collecting my prescription. | Positive | Positive | 0.5184 | 0.2578 | 0.2238 | 0.5184 |
| Paspas ang registration ug sayon sabton ang steps The restroom looked clean and smelled fresh. This happened during a follow-up consultation samtang nangayo og tabang sa reseta; it affected how we completed the visit. | Positive | Positive | 0.6754 | 0.1601 | 0.1645 | 0.6754 |
| During the afternoon schedule: the health worker explained where to go and assisted me. Mobalik ko kon kinahanglan Mapailubon ug buotan ang nurse sa pag-atiman. | Positive | Positive | 0.8476 | 0.0806 | 0.0718 | 0.8476 |
| My comment noong sinamahan ko ang nanay ko is this: walang makapagsabi kung saang counter ako lalapit. | Negative | Negative | 0.2675 | 0.5141 | 0.2184 | 0.5141 |
| After my morning checkup, the pharmacy queue was confusing and moved very slowly Lisod gamiton ang entrada kon naka-wheelchair. | Negative | Negative | 0.1816 | 0.6822 | 0.1362 | 0.6822 |
| Pagkatapos ng morning checkup: hindi available ang serbisyo at walang paliwanag. Dili unta kini masinati sa uban Kulang ang chairs ug init kaayo ang hulatanan. | Negative | Negative | 0.1017 | 0.7979 | 0.1004 | 0.7979 |
| Pakimarkahan ang accessible route mula sa gate; ito ang napansin ko sa una kong visit ngayong buwan. | Neutral | Neutral | 0.2962 | 0.2644 | 0.4393 | 0.4393 |
| I came with a relative to ask about available services; this is what I observed at the barangay health visit Pakiconsider ang numbered queue tuwing busy days. | Neutral | Neutral | 0.1405 | 0.1285 | 0.7309 | 0.7309 |
| Pakiconsider ang numbered queue tuwing busy days pagkatapos ng morning checkup; makatabang kini sa mga bisita Makatabang ang service guide sa entrada para sa bisita. | Neutral | Neutral | 0.0738 | 0.0715 | 0.8547 | 0.8547 |

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
