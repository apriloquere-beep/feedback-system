# SFMS Sentiment Labeling Guidelines

## Scope

Label the meaning of text entered in the SFMS **Comments / Suggestions** field for community-based health services. Text may describe staff interactions, consultation, registration, waiting time, cleanliness, facilities, accessibility, medicine or supply availability, and patient or visitor experiences.

Use exactly one of: `Positive`, `Negative`, or `Neutral`. `Mixed` and `Not Sure` are not training labels. If a sample cannot be labeled reliably after review, exclude it from training and record it separately for adjudication.

## Positive

Use `Positive` for clear satisfaction, appreciation, praise, approval, reassurance, or a beneficial experience.

- “The nurse explained my medicine clearly and treated me kindly.”
- “Maayo kaayo ang serbisyo, salamat sa inyong tabang.”
- “Dili lang perfect, pero satisfied ko sa consultation.”
- “Not bad ang service; mabilis at maayos naman.”

Do not label a request Positive merely because it contains words such as “clean,” “improve,” or “faster.”

## Negative

Use `Negative` for clear dissatisfaction, poor treatment, excessive delay, unavailable care or supplies, unsafe or unpleasant conditions, lack of information, or another service deficiency.

- “The staff were rude and did not explain the process.”
- “Dugay kaayo ang hulat ug wala mi gi-update.”
- “Dili kaayo maayo ang service.”
- “The medicine I needed was unavailable.”

A polite complaint remains Negative: “Please clean the CR because it smelled bad.”

## Neutral

Use `Neutral` for factual observations, questions, requests, and suggestions without clear praise or dissatisfaction.

- “Please add more chairs in the waiting area.”
- “Improve the waiting time.”
- “Can you provide more medicine in the pharmacy?”
- “Wala koy problema sa registration.”

A request is not automatically a complaint. Determine whether the writer reports an existing negative condition or simply proposes a change.

## Negation

Interpret the whole phrase, not an isolated word.

- “not good,” “not at all helpful,” “hindi maganda,” and “dili maayo” are Negative.
- “not bad,” “no problem,” “walang problema,” and “walay problema” are not Negative without other adverse context.
- In longer sentences, determine what the negation actually modifies.

## Dominant sentiment

When text contains both favorable and unfavorable clauses, choose the sentiment that represents the main or final evaluation.

- “Friendly ang staff, pero sobra ka dugay ang hulat.” → Negative
- “Dugay gamay ang queue, pero excellent ang treatment overall.” → Positive
- “The nurse was helpful, but the room was small.” → Neutral when neither observation clearly dominates

If trained reviewers cannot identify a dominant sentiment, exclude the sample rather than inventing a fourth class.

## Mixed-language and code-switched feedback

Read English, Bisaya, and Tagalog words as one statement. Do not label based on the language used. Preserve natural spelling and grammar when meaning remains clear.

- English–Bisaya: “Okay ang consultation, pero dugay kaayo ang release sa reseta.”
- English–Tagalog: “Helpful ang nurse but the queue was too long.”
- Bisaya–English: “Salamat sa staff, very clear ang instructions.”

## Healthcare-specific rules

- Judge availability statements by context: asking whether medicine can be stocked is Neutral; reporting that prescribed medicine was unavailable is Negative.
- A stated long or excessive wait is Negative; a request to reorganize the queue without dissatisfaction is Neutral.
- Praise for courtesy, clear explanations, privacy, cleanliness, accessibility, or prompt care is Positive.
- Reports of rude treatment, unsafe sanitation, missing supplies, inaccessible facilities, or unexplained delays are Negative.
- Factual operating-hour, signage, seating, ventilation, or process suggestions are Neutral unless accompanied by praise or a complaint.
- Never use ratings, respondent identity, or service name to override the meaning of the text.

## Review process

Every synthetic sample starts with `review_status=Pending`. A human reviewer should check naturalness, label, language group, topic, privacy, and duplication. Ambiguous and conflicting cases require a second reviewer and adjudication before use.
