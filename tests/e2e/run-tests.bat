@echo off
:: ScoutKeeper E2E Test Runner
:: Run from cmd.exe or double-click in Explorer.
::
:: Usage:
::   run-tests.bat                                      — full comprehensive suite
::   run-tests.bat specs/comprehensive/03-members.spec.ts  — single file
::   run-tests.bat specs/auth.spec.ts                   — original specs

wsl bash "/mnt/c/Users/kevin/OneDrive - QuadNine Ltd/Claude/sk10/tests/e2e/run-tests.sh" %*
