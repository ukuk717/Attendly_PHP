# Attendly Agent Guide

- All responses to users must be in Japanese. If the output is in English, it must be translated before output.
- Please ensure that Japanese text is entered strictly in UTF-8 format. Mixing with other encodings such as Shift-JIS is not permitted.
- The specifications for the Lolipop server are detailed in the `lolipop_server_spec.html` file. Please build your project to function properly using the specifications available on the Standard Plan for this server.
# Critical AGENTS Warning
- Please ensure you follow the `# 移行のゴール` outlined in `docs/plan/php_next_steps.md`. This is not a new project, but merely an implementation for the PHP version.
- Please exercise extreme caution to prevent security risks and implement solutions that do not conflict with known issues.
- Writing code with even the slightest problem is strictly prohibited.
- In principle, the execution of Python code is prohibited. If there is anything that can only be executed using Python, be sure to confirm with the user the reason. The arbitrary execution of Python code is prohibited.However, this does not apply when using Serena (MCP Server).
- If there are any items listed in the `needs fix` section of `issues.md` that have not been marked as fixed, you must review and implement the necessary fixes.
- If the `needs fix` section is blank, no action is necessary.
- If you decide not to implement any of these items based on your own judgment, you must present me with a reason that I find acceptable. Implementing changes without permission is not permitted.
- Always use context7 when I need code generation, setup or configuration steps, orlibrary/API documentation. This means you should automatically use the Context7 MCPtools to resolve library id and get library docs without me having to explicitly ask.

