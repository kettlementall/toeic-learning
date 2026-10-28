# TOEIC Learning Platform — Specification

This document specifies the functional behavior and technical design of the
TOEIC Learning Platform. For setup and operation, see [README.md](./README.md).

---

## 1. Overview

### 1.1 Purpose
Help a learner build and retain TOEIC-relevant English vocabulary and grammar
through three reinforcing loops:

1. **Acquire** — look up real words; AI enriches them with Chinese meaning and
   TOEIC usage notes.
2. **Retain** — an SM-2 spaced-repetition scheduler resurfaces words just before
   they're forgotten.
3. **Assess & adapt** — AI-generated quizzes target the learner's weak words and
   weak grammar points; results feed back into both the scheduler and the
   adaptive question selector.

### 1.2 Scope & assumptions
- **Multi-user.** Session-cookie authentication; every user has a private
  training database. Personal data (`user_words`, `quizzes`, `review_logs`) is
  partitioned by `user_id`; the `words` dictionary/seed cache is shared global
  reference data. Open self-service registration. An admin role (`is_admin`)
  unlocks user management. Accounts can be disabled (`is_active=false`).
- **Traditional Chinese** is the translation/explanation language throughout AI
  prompts.
- AI features are **optional**: with no `ANTHROPIC_API_KEY`, dictionary lookups
  still function and AI features are disabled with a visible banner.

### 1.3 Non-goals
- Listening/reading full-test simulation (Parts 1–4, 6, 7).
- Sharing or social features; cross-user data sharing.
- Email verification / password-reset-by-email flows (admins reset passwords).
- Mobile native apps (the web UI is responsive instead).

---

## 2. Domain Model

### 2.1 Entities

#### `users` — accounts
Laravel's default users table plus two role flags.

| Column        | Type        | Notes                                              |
|---------------|-------------|----------------------------------------------------|
| `name`        | string      | **unique** — the login id                          |
| `email`       | string      | nullable, optional (unique when present)           |
| `password`    | string      | hashed                                             |
| `is_admin`    | boolean     | default false; unlocks the admin area              |
| `is_active`   | boolean     | default true; false ⇒ cannot log in                |

Owns `user_words`, `quizzes`, `review_logs` (each `cascadeOnDelete`).

#### `words` — dictionary cache / word catalog
Canonical, deduplicated record of a word's reference data. Populated from the
seed list, the dictionary API, or AI-generated new words.

| Column          | Type        | Notes                                              |
|-----------------|-------------|----------------------------------------------------|
| `word`          | string(100) | **unique**, lowercased                             |
| `phonetic`      | string      | IPA, nullable                                      |
| `audio_url`     | string      | pronunciation audio, nullable                      |
| `part_of_speech`| string(20)  | primary POS (mirrors first meaning)                |
| `definition_en` | text        | primary English definition                         |
| `definition_zh` | text        | primary Chinese translation                        |
| `meanings`      | json        | `[{pos, definition_en, definition_zh, example}]` per POS |
| `example`       | text        | primary example sentence                           |
| `toeic_note`    | text        | AI TOEIC usage tip (Chinese)                       |
| `mnemonic`      | text        | AI memory aid / mnemonic (Chinese), nullable       |
| `synonyms`      | string      | comma-joined, ≤6                                   |
| `level`         | tinyint     | difficulty 1–5, default 3                          |
| `category`      | string(50)  | business/finance/office/travel/general/…           |
| `source`        | string(10)  | `seed` \| `api`                                    |
| `raw_json`      | json        | raw dictionary API entry                           |

#### `user_words` — personal vocabulary + SM-2 state
| Column            | Type         | Notes                                                       |
|-------------------|--------------|-------------------------------------------------------------|
| `user_id`         | FK→users     | owner, `cascadeOnDelete`                                    |
| `word_id`         | FK→words     | nullable, `nullOnDelete`                                    |
| `word`            | string(100)  | lowercased; **unique per user** (`user_id, word`)          |
| `source`          | string(15)   | `search`\|`manual`\|`quiz_weak`\|`quiz_new`\|`ai_review`    |
| `notes`           | text         | user notes                                                  |
| `tags`            | string(200)  | free-text, comma-style; matched by `LIKE`                  |
| `ease_factor`     | decimal(4,2) | SM-2 EF, default 2.5, floor 1.3                            |
| `interval_days`   | int          | current interval, default 0                                |
| `repetitions`     | int          | consecutive successful reviews                             |
| `lapses`          | int          | cumulative failures (≥4 ⇒ leech)                           |
| `next_review_at`  | timestamp    | due date                                                    |
| `last_reviewed_at`| timestamp    | last graded                                                 |

#### `quizzes`
| Column        | Type        | Notes                                          |
|---------------|-------------|------------------------------------------------|
| `user_id`     | FK→users    | owner, `cascadeOnDelete`                        |
| `title`       | string(200) | e.g. "Part 5 Grammar Quiz · 06/04 14:30"       |
| `type`        | string(20)  | `vocab_mc`\|`part5_grammar`\|`fill_blank`\|`news_reading` |
| `scope`       | string(10)  | `builtin`\|`custom`\|`mixed`\|`news`            |
| `status`      | string(20)  | `pending`\|`completed`                          |
| `score`       | tinyint     | correct count, nullable                         |
| `total`       | tinyint     | question count                                  |
| `ai_review`   | json        | `{summary, weaknesses, review_words[], review_grammar[]}` |
| `article`     | json        | news quizzes only: `{source, topic, title, url, published_at, passage, level, suitable, suitability_note, summary, vocab[]}` |
| `completed_at`| timestamp   | set on submit                                   |

#### `quiz_questions`
| Column          | Type        | Notes                                  |
|-----------------|-------------|----------------------------------------|
| `quiz_id`       | FK→quizzes  | `cascadeOnDelete`                      |
| `word`          | string(100) | set for vocab/fill-blank questions     |
| `grammar_point` | string(30)  | set for Part 5 questions               |
| `question`      | text        | sentence with `____` blank             |
| `options`       | json        | array of 4 option strings              |
| `correct_answer`| string(5)   | letter `A`–`D` (uppercased)            |
| `explanation`   | text        | Chinese rationale                      |
| `user_answer`   | string(5)   | letter, nullable                       |
| `is_correct`    | boolean     | nullable until graded                  |

#### `review_logs` — analytics event stream
One row per graded event (review, vocab quiz answer, grammar quiz answer, AI
boost). Drives all dashboard stats and adaptive grammar selection.

| Column          | Type        | Notes                                   |
|-----------------|-------------|-----------------------------------------|
| `user_id`       | FK→users    | owner, `cascadeOnDelete`                |
| `user_word_id`  | FK→user_words | nullable                              |
| `quiz_id`       | FK→quizzes  | nullable                                |
| `category`      | string(50)  | denormalized from the word              |
| `part_of_speech`| string(20)  | denormalized from the word              |
| `grammar_point` | string(30)  | for Part 5 events                       |
| `quality`       | tinyint     | 0–5 (SM-2 grade; `<3` counts as wrong)  |
| `reviewed_at`   | timestamp   | event time                              |

### 2.2 Relationships
- `User` 1—* `UserWord` / `Quiz` / `ReviewLog` (all `cascadeOnDelete`).
- `Word` 1—* `UserWord` (`UserWord.dictionary` = `belongsTo Word`).
- `Quiz` 1—* `QuizQuestion`.
- `ReviewLog` references `UserWord` and/or `Quiz` (both nullable).

---

## 3. Functional Requirements

### 3.0 Accounts & access control (`Auth\*`, `Admin\UserController`, `EnsureUserIsAdmin`)
- **FR-A1** Open registration: `GET/POST /register` validates a unique account
  id (`name`), an optional unique email, and a confirmed password (min 8);
  creates a non-admin, active user, logs them in, regenerates the session.
- **FR-A2** Login: `GET/POST /login` authenticates by **account id** (`name`)
  via the `web` guard with an added `is_active = true` constraint, so disabled
  accounts cannot sign in. Email is not used for login. `POST /logout`
  invalidates the session.
- **FR-A3** All application routes (dashboard, words, vocabulary, review, quiz)
  sit behind the `auth` middleware; guests are redirected to `login`.
- **FR-A4** Every owned query is scoped by `auth()->id()`. Route-model-bound
  `UserWord`/`Quiz` additionally assert ownership and return **403** for
  non-owners. The shared `words` cache is never user-scoped.
- **FR-A5** Admin area (`/admin/users`, `admin` middleware ⇒ `is_admin` else
  403): list users with per-user stats (vocab count, completed-quiz count, last
  activity); create users; toggle `is_active`; reset a password; delete a user
  (cascades their owned rows). An admin cannot disable or delete **their own**
  account.

### 3.1 Word lookup (`WordController`, `DictionaryService`)
- **FR-1** `GET /words/lookup?q=<term>` returns JSON for a single word.
- **FR-2** Lookup pipeline: **cache** (`words` row with a non-null
  `definition_en`) → **dictionaryapi.dev** → **Claude enrich**. A `force` flag
  bypasses the cache (used for backfilling meanings).
- **FR-3** Dictionary normalization keeps **one entry per part of speech**
  (first definition + example each), aggregates ≤6 unique synonyms, extracts
  phonetic + first available audio.
- **FR-4** Enrichment asks Claude to translate **each** meaning into Chinese in
  the same order/count, plus one overall TOEIC note; results are zipped back
  onto `meanings` and the primary `definition_zh`.
- **FR-5** A successful lookup **auto-adds** the word to `user_words` with
  `source=search` and `next_review_at=today` (idempotent via `firstOrNew`).
- **FR-6** Errors: empty term ⇒ 422; not found ⇒ 404; both as JSON messages.
- **FR-6a** **Example backfill/fallback.** Words can enter the library without
  an example (the dictionary API had none, or the word was auto-added from a
  quiz/AI suggestion). Two paths fill the gap, both persisting the example to
  the `words` cache so it is generated only once:
  - **Batch** — `php artisan words:backfill-examples` links orphaned
    `user_words` to a dictionary entry, then AI-generates an example for any
    library word still missing one (`--limit`, `--chunk` options).
  - **On the fly** — `GET /words/example?q=<term>` (`WordController::example`)
    returns/generates a single example. The daily-review card requests it when
    the answer is revealed; each vocabulary-list card exposes a "Generate
    example" button.
- **FR-6b** **Memory aids (mnemonics).** Every word can carry an AI-generated
  Chinese mnemonic (`words.mnemonic`: 諧音/字根字首/拆字/聯想), generated once
  and shared, surfaced in Word Search, My Vocabulary, and Daily Review. Words get
  it at acquisition with **no extra AI request**: `enrich()` (lookup) returns it
  alongside the Chinese/TOEIC note, and `generateNewWords()`/`generateNewsQuiz()`
  return it with each generated/extracted word. Words that predate the feature or
  arrived without one are filled two ways, both persisting to the `words` cache:
  - **Batch** — `php artisan words:backfill-mnemonics` (`--limit`, `--chunk`,
    `--user-id`) generates mnemonics for library words still missing one.
  - **On the fly** — `GET /words/mnemonic?q=<term>` (`WordController::mnemonic`)
    returns/generates a single mnemonic. The daily-review card requests it on
    reveal; the Word Search and vocabulary-list cards expose a "產生記憶小技巧"
    button.

### 3.2 Vocabulary library (`VocabularyController`)
- **FR-7** `GET /vocabulary` lists `user_words` (newest first), paginated 30,
  with the related dictionary entry eager-loaded.
- **FR-8** Filterable by `source` (exact) and `tag` (`LIKE`), preserving query
  string across pages; distinct sources offered as filters.
- **FR-9** `PUT /vocabulary/{id}` updates `notes` (≤1000) and `tags` (≤200).
- **FR-10** `DELETE /vocabulary/{id}` removes the word.

### 3.3 Spaced repetition (`SpacedRepetitionService`, `ReviewController`)
- **FR-11** Due = `next_review_at <= now`, **minus the cards today has already
  finished with** (`UserWord::notClearedToday()`: a word answered correctly
  today drops out; one answered and *missed* stays, because `demote()` put it
  back on today on purpose). `dueWords()` orders that queue by, in turn:
  *priority* (words the user looked up and has not been tested on lead —
  `UserWord::freshSearch()`), *missed today* (a card that just failed is
  relearning, and must not sit behind every stale card in the library), then
  *relative overdueness* (`elapsed / interval`, so a 2-day card a week late
  beats a 200-day card a week late).
  **There is no daily quota.** The caller passes the limit it wants and takes
  that much off the front; how much gets reviewed is set by how much the
  learner studies, not apportioned per day in advance. `backlogCount()` is
  everything ripe, and it is what the dashboard shows.
- **FR-11a** `GET /review` renders the front of that queue as flashcards, a
  sitting at a time (`ReviewController::SESSION_SIZE`, a UI choice and not a
  scheduling one). It is an **optional** surface: quizzes (§3.4) drill the same
  cards. Nothing anywhere runs on a timer — no cron, no queue worker, no
  nightly job — because nothing needs to: the queue is a query, so it is always
  current the moment it is read.
- **FR-12** `POST /review/{userWord}/grade` with `quality` 0-5 applies SM-2 (see
  §4.1) and returns `{ok, next_review_at, interval_days, suspended}` as JSON.
- **FR-13** Every grade writes a `review_log` (denormalizing the word's category
  and POS).
- **FR-14** `demote(word, source)` is the "got it wrong / flagged" path:
  creates the user_word if missing (EF 2.2) or lowers EF by 0.2 (floor 1.3),
  resets repetitions/interval, increments `lapses`, schedules for **today**, and
  logs quality 1.
- **FR-14a** `queueForReview(word, source)` is the "worth another look" path and
  is **not** a failure: no lapse, no ease change, no log. A new word joins the
  library due today; an existing one is pulled to today with its interval
  re-anchored to the real gap since its last review, so the early look cannot
  stretch it. Suspended and already-due cards are left alone.
- **FR-14b** *(withdrawn.)* `pullForward()` is gone. Nothing drags an unripe
  card into today: studying harder empties the queue faster, but it cannot make
  a card ripe sooner, because forgetting runs on elapsed time and not on
  effort. A sitting that exhausts the queue falls through to ordinary practice
  (`weakWords()`), which is logged and reschedules nothing — see §4.1b.
- **FR-14c** One bad day costs one lapse: `lapsedToday()` suppresses the second
  and later ease/lapse penalties on the same card on the same day, however they
  arrive (quiz miss, "Forgot" in review, AI flag).
- **FR-15** A word with `lapses >= srs.leech_flag_at` is a **leech**
  (`UserWord::leeches()` scope, `is_leech` accessor, surfaced on the dashboard).
  At `srs.leech_suspend_at` it is automatically **suspended**
  (`suspended_at`) and leaves every queue — `active()` is applied throughout.
  `resume()` (`POST /review/{userWord}/resume`) clears the suspension and
  relearns from scratch (lapses/repetitions 0, interval 1, due today).

### 3.4 Quiz generation (`QuizController`, `QuizBuilderService`, `ClaudeService`)
- **FR-16** `POST /quiz` validates: `type∈{vocab_mc,part5_grammar,fill_blank,news_reading}`,
  `mode` (string), `count` 1–20, optional `category`, `level` 1–5, `points[]`,
  `topic`.
- **FR-17** Requires an API key; otherwise returns with an error banner.
- **FR-18** **Vocab/fill-blank**: `QuizBuilderService::selectWords()` chooses
  words by mode (§4.2), then Claude generates one question per word. The smart
  mix sources its review portion **due-first** from `dueWords()` and falls back
  to weighted sampling only when the queue is spent — so a quiz slot lands on a
  card the schedule will actually advance.
- **FR-18a** Quizzes in one sitting do not tread on each other, in **both**
  `smart` and `weak` modes. Words answered correctly today are out of the due
  queue altogether (FR-11); on top of that, words sitting in a quiz **built
  today and never submitted** are held back, since nothing has been graded yet
  and they all still look due. A word *missed* today is deliberately kept — see
  FR-11 and FR-14c. Held back, not excluded: `weakWords()` falls back to the
  whole library when that empties the pool, and the random top-up in §4.2 still
  reaches them, so a long sitting cannot run dry. The unfinished-quiz rule is
  scoped to today, so a quiz abandoned weeks ago does not keep its words out of
  circulation for good.
- **FR-19** **Part 5**: `selectGrammarPoints()` chooses grammar codes
  (adaptive or specific, §4.3); Claude generates one question per code.
- **FR-20** Persist a `Quiz` (`status=pending`) and its valid `QuizQuestion`s
  (skipping any without `question`/`options`); `correct_answer` uppercased;
  `total` corrected to the actual stored count. Each question's options are
  **shuffled** at save and the correct letter remapped (`shuffleOptions`), so
  the answer isn't biased toward a fixed position (LLMs favor "A"); prompts
  therefore explain by option content, not letter. Applies to all generated
  quiz types (vocab/Part 5/fill-blank/news).
- **FR-21** Any new `word` referenced by a question is ensured present in the
  library (`source=quiz_new`, due today) — so it joins the back of the queue
  like anything else.
- **FR-22** Empty generation ⇒ error banner, no quiz created.

### 3.4a News-reading quiz (`QuizController::storeNews`, `NewsService`, `ClaudeService::generateNewsQuiz`)
- **FR-22a** `POST /quiz` with `type=news_reading` and an optional `topic`
  (`press_release`\|`us_business`\|`business`\|`technology`) builds a reading quiz
  from a recent article. Feeds are ordered most-TOEIC-like first (Part 7 reads
  business documents, so corporate press releases sit closest to the exam); an
  unconfigured `topic` falls back to the first feed, not a fixed key. Requires
  an API key (else error banner, like other AI quizzes).
- **FR-22b** `NewsService::pickArticle()` reads the topic's RSS feed, skips
  articles the user already did (matched against recent `news_reading` quizzes'
  `article.url`), and attaches a **reading passage**: best-effort full text
  scraped from the article page (`<p>` extraction, capped ~1800 chars), falling
  back to the RSS summary when scraping fails or yields too little. No usable
  article ⇒ error banner.
- **FR-22b-i** Passage extraction is scoped to `<article>` when the page has
  one, so a press-release page's links to *other* releases stay out of the
  passage; a page without one, or whose `<article>` is too thin to be the body,
  falls back to the whole document. Paragraphs matching a boilerplate stop-list
  (newsletter pitches, tip lines, copyright, market-data disclaimers) are
  dropped individually, so an inline promo costs only itself.
- **FR-22b-ii** `article.source` is the publisher name, derived from the feed
  host by `NewsService::sourceFor()`, so adding a feed needs no second config
  entry. Adding a feed requires checking its article pages survive the `<p>`
  scrape — a feed that scrapes to nothing degrades silently to the much shorter
  RSS summary (NPR and Reuters were tried and rejected on this basis).
- **FR-22c** A single `generateNewsQuiz(title, passage, learnerProfile)` call
  returns, in one shot: a difficulty `level` (1–5) and a `suitable` verdict for
  *this* learner (profile = library size + recent quiz accuracy), a Chinese
  `suitability_note` and `summary`, ~4 reading-comprehension questions, and
  5–8 extracted vocab words. Comprehension questions store neither `word` nor
  `grammar_point`. The profile also carries up to 25 `toeic_core` words that
  occur in the passage (any inflection) and the learner does not own yet, in
  rank order (`QuizController::unlearnedCoreWordsIn`); the prompt has the
  extraction draw from those first and avoid legal/medical/political/military
  vocabulary.
- **FR-22c-i** The question set follows TOEIC Part 7: **the first question is a
  negative-factual (NOT/True) question**, whose three distractors must each be
  explicitly stated in the passage and whose answer must be absent from or
  contradicted by it — the point of the type is per-option verification against
  the text, so an ambiguous option would make it unanswerable. The remaining
  three spread across main idea, detail, inference and vocabulary-in-context.
  Explanations for the NOT question must say where each of the other three
  appear. Still absent from Part 7 coverage: sentence-insertion, quoted-intent
  (needs chat-format passages) and multi-passage cross-referencing.
  Options are shuffled at save, which this type tolerates because its options
  are content statements and explanations cite option text, never A/B/C/D.
- **FR-22d** Extracted vocab is added to the library (`addNewsVocab`): each word
  is upserted into the shared `words` cache **without clobbering** a richer
  existing entry, then added to `user_words` with `source=news` and
  `next_review_at=today` (idempotent) — so it immediately enters the SM-2 review
  queue and weak-word quizzes. The difficulty verdict is shown but never blocks
  generation; the create form offers picking another article.

### 3.5 Taking & grading (`QuizController`)
- **FR-23** `GET /quiz/{quiz}` shows the quiz; a completed quiz redirects to
  review.
- **FR-24** `POST /quiz/{quiz}/submit` accepts `answers[questionId]=letter`.
  Per question: record `user_answer`/`is_correct`; then:
  - vocab/fill-blank with a `word`: correct ⇒ `quizCorrect(word)`; wrong ⇒
    `demote(word, 'quiz_weak')`.
    `quizCorrect()` credits `QUIZ_CORRECT_QUALITY` (**4**, not 5: recognising a
    word among four options is not recall, and at quality 4 the SM-2 ease delta
    is exactly 0, so the interval still grows by `interval x ease` but a lucky
    hit cannot raise ease). It runs full SM-2 only when the card is **due or has
    never been reviewed**; a correct answer on a card that is not due yet is an
    *early peek* — logged for stats, schedule untouched, since grading it would
    stretch the interval as if a full one had passed.
  - grammar: write a `review_log` with `quality = correct ? 5 : 1` and the
    `grammar_point` (+ denormalized category/POS if the word exists in `words`).
  - news comprehension (no `word`, no `grammar_point`): counts toward `score`
    only — **no** `review_log` (it has no category/POS/grammar dimension and
    would otherwise pollute the weakness stats).
- **FR-25** Save `score`, `status=completed`, `completed_at`, then redirect to
  review.

### 3.6 AI review (`QuizController::review`, `ClaudeService::reviewQuiz`)
- **FR-26** On first view of a completed quiz, generate `ai_review`
  (`{summary, weaknesses, review_words[], review_grammar[]}`) once and persist.
- **FR-27** **Vocab quizzes only**: each AI-suggested `review_word` is looked up
  via the dictionary; if it resolves to a real word **not already tested in this
  quiz**, it is `queueForReview`d with `source=ai_review` (FR-14a) — queued for
  today, not penalised: no lapse, no ease change, no failure log, and its
  interval re-anchored to the real gap. Grammar quizzes are instructed to return
  empty `review_words`.
  Since tested words are excluded, every suggestion the learner does not
  already own is new intake, so those are taken **only from the curated
  `toeic_core` list** (lemma-matched). Without that the review drifts into
  whatever its sentences were about — courtrooms, vaccines — and each such card
  competes with the core list for review time. The prompt also steers away from
  legal/medical/political/military vocabulary.
- **FR-28** AI-suggested weak grammar points are normalized (code or Chinese
  label) and recorded as `review_log` rows with `quality=1`
  (`boostGrammar`), nudging the adaptive selector. These decay naturally as the
  point is later answered correctly.
- **FR-28a** **News quizzes** get comprehension-focused feedback
  (`reviewNewsQuiz`) and return empty `review_words`/`review_grammar`: their
  vocab loop already closed at creation (FR-22d), and comprehension questions
  map to no vocab word or grammar point.

### 3.7 Dashboard (`DashboardController`)
- **FR-29** Cards: total words, "Ready to Review" (`backlogCount()` — the real
  number of ripe cards, not a day's ration), completed quizzes, average
  accuracy. The copy says the quizzes work through these — the review page is
  offered as an optional flashcard drill, not as work owed.
- **FR-30** Recent score trend (last 10 completed quizzes, chronological).
- **FR-31** Grammar weakness ranking: per `grammar_point`, error rate =
  `wrong/total` where wrong = `quality<3`, sorted desc.
- **FR-32** Vocab weakness by part of speech (same error-rate computation).
- **FR-33** Leech list (top 10 by `lapses`).
- **FR-34** Latest AI-recommended grammar focus from recent reviews
  (de-duplicated, human-labeled).
- **FR-34a** **Reading score estimate** (`ScoreEstimateService`). A rough TOEIC
  Reading score (5–495) with a likely range and a confidence level, from the
  learner's own quiz answers over the last `toeic_estimate.window_days`.
  **Listening is not estimated** — nothing in the app tests it.
  - Reads `quiz_questions.is_correct` only, never `review_logs`: `boostGrammar()`
    (FR-28) writes quality-1 rows for AI-suggested points, which would count as
    wrong answers that were never given.
  - Three components: Part 5 accuracy (`part5_grammar`), Part 7 accuracy
    (`news_reading`), and vocabulary measured on **first exposure** — the first
    question ever asked about each word that entered through a quiz
    (`source=quiz_new`). Ordinary vocab-quiz accuracy is not used: the selector
    mostly asks about words already being studied, so it reports retention of
    the library, not how much of the TOEIC list was known.
  - Weighted to the real section (Part 5 30 / Part 6 16 / Part 7 54 items;
    Part 5 taken as 40% vocabulary, Part 6 as half grammar and half reading),
    scaled by `ai_ease_factor`, and converted through the `conversion` table.
    A missing component borrows from the nearest one present.
  - Range = ±1.96 binomial SE of the composite, never narrower than ±25.
    Confidence rises with each component meeting `min_questions`; with no Part 7
    answers it is at most *low*. Under `min_total` answers, or with neither
    Part 5 nor Part 7 answered, no score is shown — the card names the quiz
    type that would help most instead.

### 3.8 Responsive UI
- **FR-35** Top navigation shows inline links ≥768px and a collapsible
  hamburger menu <768px (Alpine.js toggle). `[x-cloak]` prevents flash on load.

---

## 4. Algorithms

### 4.1 SM-2 spaced repetition (`SpacedRepetitionService::grade`)
Input: a `UserWord` and `quality ∈ [0,5]` (clamped).

```
repeatFailure = quality < 3 and this card already logged a failure today

if quality < 3:                      # failed recall
    repetitions = 0
    interval_days = 1
    if not repeatFailure: lapses += 1
else:                                # recalled
    repetitions += 1
    interval_days = repetitions == 1 ? 1
                  : repetitions == 2 ? 6
                  : round(creditedInterval() * ease_factor)

# SuperMemo-2 ease-factor update, skipped on a repeat failure
if not repeatFailure:
    ease_factor = max(1.3,
        round(ease_factor + (0.1 - (5-quality)*(0.08 + (5-quality)*0.02)), 2))

last_reviewed_at = now
next_review_at   = schedule(max(1, interval_days))      # §4.1a
if lapses >= srs.leech_suspend_at: suspended_at = now   # FR-15
log(quality)
```

`creditedInterval()` is the SM-2 **late-review bonus**: the scheduled interval
plus half of however long the card actually survived past its due date
(`scheduled + (elapsed - scheduled) / 2`). Recalling a card that was overdue
proves the interval was too short.

### 4.1a Scheduling (`schedule`)
`today() + interval`, and nothing else. A date says when the card is ripe, not
when it must be done, so there is no calendar to flatten and no reason to nudge
a card off its own interval. Anchored to `today()`, not `now()`, so a day is a
whole day.

### 4.1b No daily budget
Nothing apportions cards across days. There is no capacity, no rebalancing, no
calendar to keep flat — the review queue is a query (FR-11) and the learner
takes as much of it as they study. Ten quizzes eat ten quizzes' worth; one eats
one.

This is deliberate and it replaced a scheduler that did assign every card a
future date. Doing so forces a second question — *how do I spread 500 overdue
cards across the days ahead?* — whose answer needs a daily capacity, a
rebalancer, load-balanced due dates, and a way to pull cards back when the
capacity was wrong. All of that was machinery for a question that only existed
because the dates were being assigned. Removing it removed the question.

What a day still decides is which cards are **ripe**, because that is what
forgetting runs on. Hence the one rule that cannot bend:

> Studying harder empties the queue faster. It does not make a card ripe
> sooner.

`quizCorrect()` enforces it — a correct answer on a card that is not ripe is
logged for stats and changes no schedule — because grading an early look would
read as evidence the memory survived an interval it never served, and inflate
that interval on a false result.

### 4.2 Word selection for quizzes (`QuizBuilderService::selectWords`)
- **`smart`** (smart mix, the default):
  1. `newWordAllowance()` = `floor(n × srs.new_word_share)`, with a floor of 1
     for quizzes of 5+ questions. A flat guaranteed share: quizzes are the only
     study surface, so one that carries no new words means the curated list
     never advances. Note it is a share of *every quiz*, so intake scales with
     how much the learner studies — see §6.1.
  2. *Review portion* = `dueFirstReview()`: take from `dueWords()` with a
     window wider than the portion (FR-11 ordering; fresh lookups capped at
     half the quiz so established cards keep a share), then `weakWords()` as a
     last resort. Words held back — lookups past the cap, plus what FR-18a
     holds for this sitting — are kept out of both. The window matters: with a
     limit of exactly the portion size, every skipped word would cost a slot.
  3. Topped up with random library words, then *new portion* = `newWords()`,
     then a final fill from the `words` catalog; truncated to `n`.
- **`weak`**: fresh lookups (capped at half the quiz) + `weakWords()` for the
  rest, with the lookups past the cap excluded from the sampling.
- **`weakWords()`**: weighted **sampling without replacement** over the active
  library, weight `= max(0.2, 2.7 − ease_factor)`, ×2.5 if overdue, with a
  cooldown (`×0.15 / 0.35 / 0.6`) on words tested in the last three quizzes so
  selection rotates instead of drilling one cluster. Cooldown only reweights —
  it never excludes.
- **`newWords()`**: the curated `toeic_core` list in `frequency_rank` order
  first, then any other locally cached word, and only then Claude generation —
  whose output is filtered against owned words by `App\Support\Lemma` (strips
  inflections only, so `offers`/`offer` collide while `investment`/`invest` stay
  separate) and persisted with `source=api`.
- **`custom`**: random `n` from a scope — `custom` (user library, optional tag
  filter), `builtin` (seed words), or any word — filtered by category/level.

### 4.3 Adaptive grammar-point selection (`selectGrammarPoints`)
- **`specific`**: intersect requested `points` with the valid set, then repeat
  cyclically to length `n` (`fillToN`).
- **`adaptive`**: per grammar point, `weight = 1.0` if unseen, else
  `0.2 + (wrong/total)*2.0` (wrong = `quality<3` in `review_logs`). Pick `n`
  points by **weighted random with replacement** — so heavily-missed points
  appear more often, but every point keeps a floor chance.

### 4.4 Grammar points (`QuizBuilderService::GRAMMAR_POINTS`)
`word_form`, `tense_voice`, `preposition`, `conjunction`, `pronoun`,
`relative`, `comparison`, `agreement`, `collocation`, `vocab_in_context`.
Human/Chinese labels are mapped in the dashboard and in
`QuizController::normalizeGrammarPoint`.

---

## 5. External Integrations

### 5.1 Anthropic Claude (`ClaudeService`)
- **Endpoint**: `POST https://api.anthropic.com/v1/messages`, headers
  `x-api-key`, `anthropic-version: 2023-06-01`. Model from
  `services.anthropic.model` (default `claude-sonnet-4-6`). 90s HTTP timeout.
- **Calls**:
  - `enrich(word, dictData)` — per-POS Chinese + TOEIC note + mnemonic (≤1024 tokens).
  - `generateNewWords(n, level?, category?)` — fresh TOEIC words, each with a
    mnemonic (≤2048).
  - `generateMnemonics(items[])` — Chinese memory aid per word, as a
    `{word: mnemonic}` map (≤4096, retries up to 3×). Backs the
    `words:backfill-mnemonics` command and the on-the-fly card/reveal fallback.
  - `generateExamples(words[])` — TOEIC-style example sentence per word, as a
    `{word: sentence}` map (≤4096 tokens). Backs the
    `words:backfill-examples` command and the on-the-fly card fallback.
  - `generateQuiz(items, type)` — vocab/Part 5/fill-blank questions (≤4096).
  - `generateNewsQuiz(title, passage, profile)` — difficulty verdict + ~4
    comprehension questions + 5–8 extracted words (each with a mnemonic), in one
    call (≤4096).
  - `reviewQuiz(quiz)` — summary, weaknesses, review words/grammar (≤2048);
    delegates to `reviewNewsQuiz()` for news quizzes (comprehension feedback,
    empty review words/grammar).
- **Output contract**: every prompt demands **JSON only**. `extractJson()`
  strips ```` ```json ```` fences, attempts a direct decode, then falls back to
  slicing from the first `{`/`[` to the last `}`/`]`. Failures are logged and
  yield `null`/`[]` so callers degrade safely.
- **Resilience**: network/non-200 errors are caught and logged; AI features
  return empty results rather than throwing. Two classes of malformed-JSON
  failure are handled specifically:
  - **Truncation** — a large batch can exceed the token budget and cut the JSON
    mid-stream. Mitigated by a generous `max_tokens` (4096 for example
    generation) and by callers batching in small chunks (the backfill command
    defaults to 20 words/request and can be lowered via `--chunk`).
  - **Delimiter glitches** — the model intermittently emits a comma instead of a
    colon between a key and value (e.g. `"slip","…"`), invalidating the whole
    object. `generateExamples()` therefore **retries up to 3 times**; since each
    request is independent, a retry almost always parses cleanly. Per-word
    matching is case-insensitive, so a partial batch still salvages whatever
    parsed.

### 5.2 Free Dictionary API (`DictionaryService`)
- `GET {DICTIONARY_API_URL}/{term}`, 15s timeout, no key required. Non-200 or
  malformed responses return the cached word (possibly `null`).

### 5.3 News RSS (`NewsService`)
- Reads public RSS feeds (`services.news.feeds`, keyed by topic — PR Newswire,
  CNBC and BBC), 15s timeout, **no key required**, browser-like `User-Agent`
  (`services.news.user_agent`). `recentArticles()` parses RSS items via
  SimpleXML and HTML-decodes title/summary (feeds double-encode entities, so
  SimpleXML's own decode leaves a literal `&amp;` behind);
  `fetchPassage()` best-effort scrapes article `<p>` text. All
  network/parse failures are caught + logged and degrade to an empty list / RSS
  summary, so a flaky feed never 500s the request.

---

## 6. Configuration

| Variable             | Required | Default                                           |
|----------------------|----------|---------------------------------------------------|
| `ANTHROPIC_API_KEY`  | for AI   | —                                                 |
| `ANTHROPIC_MODEL`    | no       | `claude-sonnet-4-6`                               |
| `DICTIONARY_API_URL` | no       | `https://api.dictionaryapi.dev/api/v2/entries/en` |
| `NEWS_FEED_*`        | no       | RSS per topic: `PRESS_RELEASE` (PR Newswire), `US_BUSINESS` (CNBC), `BUSINESS`/`TECHNOLOGY` (BBC) |
| `NEWS_USER_AGENT`    | no       | browser-like UA for fetching RSS/articles         |
| `DB_*`               | yes      | MySQL (Docker) / SQLite (local)                   |

Config keys live in `config/services.php` under `anthropic.*`,
`dictionary.url`, and `news.*`.

### 6.1 Scheduler tuning (`config/srs.php`)

| Key                      | Default | Meaning                                                      |
|--------------------------|---------|--------------------------------------------------------------|
| `new_word_share`         | 0.2     | Fraction of a quiz given to unseen words; floor of 1 for quizzes of 5+ (§4.2). |
| `leech_flag_at`          | 4       | Lapses at which a word is flagged on the dashboard (FR-15).  |
| `leech_suspend_at`       | 8       | Lapses at which it is suspended out of the rotation.         |

`new_word_share` is the only dial here, and it is the one that matters. It is
a share of **every quiz**, so intake scales with study: ten quizzes a day is
ten times the new words. A new word costs roughly seven reviews over its first
few years, and nothing downstream absorbs that any more — the queue simply
grows — so raising it does not buy more learning, it lengthens the tail of
words waiting their turn (§9).

### 6.2 Score estimate (`config/toeic_estimate.php`)

| Key              | Default | Meaning                                                         |
|------------------|---------|-----------------------------------------------------------------|
| `window_days`    | 90      | Only quizzes completed this recently count (FR-34a).           |
| `ai_ease_factor` | 0.92    | Composite accuracy is scaled by this, assuming AI questions run easier than the exam. Uncalibrated. |
| `min_questions`  | part5 40, part7 20, vocab 50 | Sample a component needs to raise confidence. |
| `min_total`      | 30      | Below this many answers in all, no estimate is shown.          |
| `conversion`     | 0.0→5 … 1.0→495 | Reading accuracy → scaled score, linearly interpolated. |

---

## 7. Seed Data
`WordSeeder` upserts ~142 curated TOEIC words (`source=seed`) across 10
categories — business, contracts, finance, general, hr, logistics, marketing,
office, technology, travel — each with POS, Chinese meaning, example, and level.
Idempotent via `updateOrCreate` on `word`.

The `seed_wells_admin_and_backfill` migration also creates a default **admin**
(login id `wells`, password `password` — change after first login) and assigns
any pre-existing single-user rows to it, before switching the `user_words`
unique key from `word` to `(user_id, word)`.

---

## 8. Security & Privacy
- **SEC-1** Session-cookie authentication (Laravel `web` guard). All app routes
  require `auth`; the admin area additionally requires `is_admin`. Per-user data
  is isolated by `user_id`, with ownership re-checked on bound routes (403 on
  mismatch). Disabled accounts (`is_active=false`) cannot log in.
- **SEC-2** The Anthropic API key lives only in `.env`, which is git-ignored and
  kept out of version control. Rotate it in the Anthropic Console if exposed.
- **SEC-3** All write routes are CSRF-protected (Laravel default; meta tag in
  the layout). Lookups and grading return JSON.
- **SEC-4** User-supplied search terms and AI-suggested words are validated and
  resolved through the dictionary before being persisted/scheduled, preventing
  empty or junk cards.
- **SEC-5** Inputs are validated (`type`, `count` 1–20, `level` 1–5, `quality`
  0–5, string length caps on notes/tags).

---

## 9. Known Limitations / Future Work
- **Demand can exceed what anyone can study.** Summing `1 / interval` over a
  library gives the reviews a day it would take to keep every card on time; a
  library where most cards sit at interval 0–1 (never passed, or in a learning
  step) asks for far more than a person does. Dropping the daily budget (§4.1b)
  does not change that arithmetic, it just stops it presenting as a wall: the
  queue is ordered by urgency, so the overflow is a tail that waits rather than
  cards exiled to a date. The dials are intake (`new_word_share`, §6.1) and
  leech handling (FR-15), never the scheduler.
- All of the "today" rules — FR-11's cleared-today, FR-18a's unfinished quiz,
  FR-11's missed-today ordering — key off `today()` in the
  **app timezone, which is UTC**. For a learner in UTC+8 the day therefore rolls
  over at 08:00 local, so a sitting that spans that hour sees the rules reset
  mid-way. Changing `config/app.php` would reinterpret every stored
  `next_review_at` (MySQL `datetime` carries no zone), so it is a data decision,
  not a config tweak. The ordering expressions read the **database** clock
  (`NOW()`, `CURDATE()`) while the PHP side reads the app's, so the two
  containers must agree on a timezone; they do today (both UTC).
- A long sitting is bounded only by the library: measured on a 608-card
  library shaped like a real one, 60 consecutive 10-question quizzes drilled
  482 distinct words and graded 480 cards with no question landing on a card
  the schedule would not advance; the 118 repeats were the deliberate re-drill
  of missed words, none seen more than twice.
- **Intake runs a structural deficit by design.** Every quiz carries new
  words, and each costs reviews for years, so a library grows demand faster
  than a learner grows throughput. Nothing absorbs that: the app drills what is
  closest to being forgotten and the long tail waits, which is the intended
  trade rather than a backlog to clear. Closing the gap is a matter of quiz
  volume, intake (§6.1), or a smaller library — never scheduling.
- Parked/suspended words are indistinguishable from auto-suspended leeches
  (one `suspended_at` column, no `suspend_reason`).
- Quiz generation is synchronous (request blocks up to ~90s on the AI call); a
  queued/async flow would improve UX for large quizzes.
- Tags are free-text matched by `LIKE` (no normalized tag table).
- Only TOEIC Part 5–style grammar and vocabulary are covered, not full-test
  reading/listening sections.
- The browser TTS fallback quality depends on the device's installed voices.
- The Reading score estimate (FR-34a) is **uncalibrated**: generated questions
  carry no measured difficulty (`words.level` is almost uniformly 3), so
  `ai_ease_factor` and `conversion` are educated defaults. Comparing estimates
  with real TOEIC results is what would tune them. Listening is not estimated.
