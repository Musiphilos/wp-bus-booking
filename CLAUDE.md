
### 1. Plan Mode Default
- Enter plan mode for ANY non-trivial task (3+ steps or architectural decisions)
- If something goes sideways, STOP and re-plan immediately - don't keep pushing
- Use plan mode for verification steps, not just building
- Write detailed specs upfront to reduce ambiguity

### 2. Subagent Strategy
- Use subagents liberally to keep main context window clean
- Offload research, exploration, and parallel analysis to subagents
- For complex problems, throw more compute at it via subagents
- One task per subagent for focused execution

### 3. Code Review Before Commit
- Run the `superpowers:requesting-code-review` skill before committing any non-trivial change
- The review agent must run BEFORE `git commit`, not after
- This applies to both backend and frontend changes

### 4. Verification Before Done
- Never mark a task complete without proving it works
- Diff behavior between main and your changes when relevant
- Ask yourself: "Would a staff engineer approve this?"
- Run tests, check logs, demonstrate correctness

### 5. Demand Elegance (Balanced)
- For non-trivial changes: pause and ask "is there a more elegant way?"
- If a fix feels hacky: "Knowing everything I know now, implement the elegant solution"
- Skip this for simple, obvious fixes - don't over-engineer
- Challenge your own work before presenting it

### 6. Autonomous Bug Fixing
- When given a bug report: just fix it. Don't ask for hand-holding
- Point at logs, errors, failing tests - then resolve them
- Zero context switching required from the user
- Go fix failing CI tests without being told how

## Task Management
1. **Plan First**: Write plan to `tasks/todo.md` with checkable items
2. **Verify Plan**: Check in before starting implementation
3. **Track Progress**: Mark items complete as you go
4. **Explain Changes**: High-level summary at each step, using plain language for a junior dev
5. **Document Results**: Add review section to `tasks/todo.md`
6. **Capture Lessons**: Update `tasks/lessons.md` after corrections

## Context Management
- When compacting, always preserve: the list of modified files, any failing test output, and the current task's acceptance criteria

## Core Principles
- **Simplicity First**: Make every change as simple as possible. Impact minimal code.
- **No Laziness**: Find root causes. No temporary fixes. Senior developer standards.

---

## WordPress MCP Integration

This project has a live MCP connection to the WordPress instance hosting the plugin.
MCP server: `lbswing-bookings`

### Deploy Workflow

After **every** code change to a plugin file, push it to WordPress via MCP:

```
mcp__lbswing-bookings__mcp-adapter-execute-ability
  ability_name: "nvf-bus-booking/admin-deploy-file"
  parameters:
    path: "<path relative to plugin root, e.g. src/Admin/Dashboard.php>"
    operation: "write"
    content: "<full file contents>"
```

Rules:
- Allowed extensions: `.php`, `.css`, `.js`, `.md`, `.json`, `.txt`, `.html`
- Always deploy **after** local edits are saved
- For **considerable changes** (new features, refactors, multi-file changes): run `superpowers:requesting-code-review` BEFORE deploying
- For **minor changes** (typos, single-line fixes, copy tweaks): deploy immediately without review

### Available MCP Abilities

| Ability | Type | Use case |
|---|---|---|
| `nvf-bus-booking/get-bookings` | read | List bookings, optionally filtered by status |
| `nvf-bus-booking/get-trips` | read | All trips with capacity, confirmed seats, availability |
| `nvf-bus-booking/get-trip-manifest` | read | Full passenger list for a trip (confirmed + waitlist) |
| `nvf-bus-booking/get-waiting-list` | read | Waitlist for a specific trip, FIFO order |
| `nvf-bus-booking/get-booking-by-email` | read | Look up a booking by participant email |
| `nvf-bus-booking/cancel-booking` | **write** | Cancel one or both directions (admin path, bypasses deadline) |
| `nvf-bus-booking/admin-deploy-file` | **write** | Write / delete / read a file in the plugin directory |

Use read abilities to verify live state before and after changes — e.g. read the current file before overwriting, or check trips/bookings to validate that data-layer changes are correct.
Use write abilities carefully: `cancel-booking` is irreversible for the passenger; `admin-deploy-file` with `operation=delete` is destructive.