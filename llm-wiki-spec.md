# Build an LLM-Wiki for Claude (and Codex)

A shareable spec for setting up a local, markdown knowledge base that your AI
coding assistant reads to work faster and more consistently. Hand this whole
file to your own Claude and it can build the same thing for you.

## What this is (and isn't)

It's a small folder of markdown files that your assistant consults instead of
re-deriving your standards, project context, and preferences from scratch every
session. The pattern follows Andrej Karpathy's April 2026 "LLM knowledge base"
idea: an LLM-maintained wiki, not a vector RAG.

- **Not a RAG.** No embeddings, no vector database, no retrieval pipeline. Those
  add infrastructure you don't need for a personal, mid-sized knowledge base.
- **The trick is selective loading.** Your assistant reads one small index file
  first, then opens only the page whose trigger matches the task. That's where
  the token savings come from (reported up to ~95% vs dumping everything into
  context).
- **It only works if the assistant is told to look there.** A folder of great
  notes is dead weight unless your always-on instructions point at the index. If
  you use two assistants (e.g. Claude for architecture, Codex for execution),
  both need the pointer, because they read different files.

When a true RAG is the better tool: corpora too large to fit a context window,
or content that changes so often a curated set can't keep up. For a
personal/standards/project knowledge base, the markdown wiki wins on simplicity.

## Architecture

```text
your-kb/
  INDEX.md                  <- router; read this first
  about-me.md               <- who you are, stack, conventions
  memory.md                 <- evolving log of projects/decisions; updated proactively
  writing-style.md          <- house style for any prose
  coding-standards.md       <- language idioms + security rules
  <topic>.md                <- one page per recurring topic
```

Three principles do the work:

1. **One index that routes.** `INDEX.md` is a table of every page with a
   plain-English "load when" trigger. The assistant matches its task to a
   trigger and opens only that page.
2. **Frontmatter on every page** so the assistant can route without reading the
   whole file:
   ```yaml
   ---
   title:
   purpose: one line — what this page is for
   load_when: explicit trigger, e.g. "writing or reviewing code"
   owner: you | assistant | shared
   last_updated: DD/MM/YYYY
   ---
   ```
3. **Wire it into the entry points.** Add a short pointer to the index in your
   assistant's always-on instructions, and in any per-project context file it
   reads.

## The pages (a starting set)

Adapt to your work. A page earns its place if its content recurs across sessions
or projects.

- **INDEX.md** — the router. The single most important file.
- **about-me.md** — role, stack, tools, coding/style conventions. Static facts.
- **memory.md** — active projects, decisions, durable preferences. The assistant
  appends here as things change.
- **writing-style.md** — your house style, the words and patterns to avoid,
  audience defaults.
- **coding-standards.md** — language idioms, formatting/linting, and
  security-by-design rules, lifted out of individual projects so they live once.
- **Topic pages** for whatever you repeat: a deployment/repo workflow, how you
  collaborate with a second AI, a guide for a specific site or product.

The win: standards that used to be copy-pasted into every project's context file
now live in one page that everything references. Less duplication, fewer tokens,
one place to update.

## Conventions that keep it useful

- **Keep pages dense and high-signal.** If a paragraph adds nothing, cut it. The
  assistant pays for every token it loads.
- **Never store anything that goes stale.** No PR/issue counts, no "current
  status", no live metrics. Store durable facts and decisions; let the live
  system be the source of truth for anything that changes.
- **Link pages to each other** with relative links so the assistant can follow a
  thread when a task spans topics.
- **Update memory proactively.** Tell your assistant to append to `memory.md`
  when a project starts, a decision lands, or you state a lasting preference,
  without being asked.
- **Match the index triggers to real tasks.** "Writing a blog post", "creating a
  repo", "reviewing code" — concrete triggers route better than vague ones.

## How to set it up

1. Pick a folder for the knowledge base.
2. Create `INDEX.md` and the pages above, each with frontmatter.
3. Add this pointer block to your assistant's always-on instructions (and to any
   per-project context file it reads):

   ```markdown
   ## Knowledge base

   Shared context lives in the KB at <ABSOLUTE/PATH/TO>/INDEX.md. Read the index
   first, then load only the page whose load_when trigger matches the task.
   Don't bulk-load everything.
   ```

4. If your assistant's "global instructions" live in a settings UI (not just a
   file), paste the pointer there too. Editing a local copy of those
   instructions does not update the live setting.
5. Test it: give the assistant two different tasks and confirm it opens only the
   matching page.

## Hand this to your own Claude

Copy-paste prompt:

> Read the spec in `llm-wiki-spec.md`. Build me a local LLM-wiki knowledge base
> following it. First interview me about my work, my stack, my coding and
> writing conventions, and the tasks I repeat, so the pages reflect me and not
> generic filler. Then create `INDEX.md` plus a starting set of pages, each with
> the frontmatter shown. Finally, add the pointer block to my assistant's
> always-on instructions and any project context files, and verify every index
> link resolves. Keep every page dense and free of anything that goes stale.

## Gotchas

- **A folder nobody reads helps nobody.** The pointer in the always-on
  instructions is what makes it real. Verify it's there.
- **Two assistants, two entry points.** If a second tool (Codex, etc.) does
  execution, it reads its own context file — put the pointer there as well.
- **Settings-UI instructions need a manual paste.** If your global instructions
  live in an app's settings rather than a file, update them there.
- **Don't let it sprawl.** Hundreds of dense pages still work with selective
  loading, but only if the index stays accurate and pages stay lean. Prune as
  you go.

---

Source for the underlying pattern: Andrej Karpathy's "LLM knowledge base" (April
2026). Coverage:
[VentureBeat](https://venturebeat.com/data/karpathy-shares-llm-knowledge-base-architecture-that-bypasses-rag-with-an),
[LLM Knowledge Base for Coding Agents (Verdent)](https://www.verdent.ai/guides/llm-knowledge-base-coding-agents),
[LLM wiki vs RAG (MindStudio)](https://www.mindstudio.ai/blog/llm-wiki-vs-rag-markdown-knowledge-base-comparison).
