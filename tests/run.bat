@echo off
REM Numra for WooCommerce — full pre-release check.
REM
REM The checks live in run.mjs now, so the release pipeline can run them on
REM Linux. This shim stays because `tests\run.bat` is in muscle memory and in
REM the docs, and breaking that to save two lines would be a poor trade.
node "%~dp0run.mjs" %*
exit /b %ERRORLEVEL%
