# Chimera PHP

Self-improving autonomous AI agent for PHP. Multi-provider LLM, persistent memory, iterative tool execution, and automatic knowledge extraction.

Port of [Chimera Agent](https://github.com/MauricioPerera/chimera-agent) to native PHP, integrating the full PHP AI agent ecosystem.

```
composer require mauricioperera/chimera-php
```

## What It Does

Chimera is an **autonomous agent** that:
1. Receives a task from the user
2. Iteratively calls tools to accomplish it (up to 25 iterations)
3. Remembers what it learned for future sessions
4. Consolidates its memory over time (dream cycle)

```
User: "List the PHP files in this project and tell me the architecture"

Chimera:
  → [tool] cli_exec("search list files")     → finds file:list
  → [tool] cli_exec("file:list --path src")  → gets file listing
  → [tool] cli_exec("file:read --path src/Chimera.php") → reads code
  → [tool] remember("Project uses facade pattern with bridges to 4 packages")
  → "The project has 25 PHP files organized as..."
  → [learning] Extracted 2 memories, 1 skill (saved for next session)
```

## Quick Start

```bash
# 1. Configure
cp .env.example .env
# Edit .env with your Cloudflare credentials

# 2. Interactive chat
php bin/chimera chat

# 3. With a different model
php bin/chimera chat --model @cf/zai-org/glm-4.7-flash
```

## Architecture

```
┌────────────────────────────────────────────────────────┐
│                    Chimera PHP Agent                     │
│                                                          │
│  User ──→ Context Builder ──→ Agent Loop ──→ Response    │
│              │ (recall)          │ (iterate)    │        │
│              ▼                   ▼              ▼        │
│           Memory              Tools         Learning     │
│           Recall             Execute         Loop        │
├───────────┬──────────────┬──────────────┬───────────────┤
│  Memory   │    Shell     │     A2E      │    LLM        │
│  Bridge   │   Bridge     │   Bridge     │  Provider     │
│  4 tools  │   2 tools    │   3 tools    │  Workers AI   │
│           │              │              │  OpenRouter    │
├───────────┴──────────────┴──────────────┴───────────────┤
│  php-agent-memory · php-agent-shell · php-a2e           │
│                  php-vector-store                         │
└──────────────────────────────────────────────────────────┘
```

## Agent Loop (The Heart)

```
While iterations < 25:
  1. Call LLM with messages + enabled tools
  2. If tool calls:
     - Execute safe tools in parallel
     - Execute unsafe tools sequentially
     - Add results to conversation
     - Anti-loop check (2x same tools → disable)
     - Loop back to step 1
  3. If text response → done

Post-response: Learning Loop extracts memories + skills
```

## Tools (9 via Bridges)

| Bridge | Tools | Source |
|--------|-------|--------|
| Memory | `recall`, `remember`, `learn_skill`, `memory_stats` | php-agent-memory |
| Shell | `cli_help`, `cli_exec` (19 commands behind) | php-agent-shell |
| A2E | `a2e_capabilities`, `a2e_validate`, `a2e_execute` | php-a2e |
| Fallback | `shell_exec`, `read_file`, `list_dir` | Built-in (if shell not available) |

## LLM Providers

### Cloudflare Workers AI (default)
```env
CHIMERA_LLM_PROVIDER=workers-ai
CHIMERA_LLM_MODEL=@cf/ibm-granite/granite-4.0-h-micro
CF_ACCOUNT_ID=your-id
CF_API_TOKEN=your-token
```

### OpenRouter (200+ models)
```env
CHIMERA_LLM_PROVIDER=openrouter
CHIMERA_LLM_MODEL=nousresearch/hermes-4-scout
OPENROUTER_API_KEY=your-key
```

## CLI Commands

```
/help      — Show commands
/tools     — List registered tools by category
/model     — Show or change model
/sessions  — List recent sessions
/search Q  — Full-text search past conversations
/dream     — Consolidate memory
/clear     — Clear conversation history
/quit      — Exit
```

## Self-Improvement (Learning Loop)

After each conversation where tools were used:
1. Sends conversation transcript to LLM
2. LLM extracts max 3 memories + 2 skills
3. Saves to php-agent-memory with dedup
4. Next session: Context Builder recalls relevant memories automatically

## Anti-Loop Protection

If the agent calls the same tools 2x consecutively:
1. Detects repeated tool signature
2. Disables all tools for next iteration
3. Forces LLM to generate text response
4. Prevents infinite loops

## The Full PHP AI Ecosystem

| Package | Role | GitHub |
|---------|------|--------|
| php-vector-store | Storage engine | [link](https://github.com/MauricioPerera/php-vector-store) |
| php-agent-memory | Brain (memory + dream) | [link](https://github.com/MauricioPerera/php-agent-memory) |
| php-agent-shell | Hands (CLI execution) | [link](https://github.com/MauricioPerera/php-agent-shell) |
| php-a2e | Orchestrator (workflows) | [link](https://github.com/MauricioPerera/php-a2e) |
| **chimera-php** | **The agent** | This repo |

## Testing

```bash
composer install
vendor/bin/phpunit    # 16 tests, 31 assertions
```

## License

MIT
