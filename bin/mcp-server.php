#!/usr/bin/env php
<?php

declare(strict_types=1);

use Milpa\AppRuntime\Console\Application;

require __DIR__ . '/../vendor/autoload.php';

// The MCP surface of this app is `php bin/coa mcp`; this file hands every call to it, and stays so the `.mcp.json`
// files that already name it keep working.
//
// It used to hold the whole server, and that was the defect (greenhouse decisions/0507): a file `create-project`
// copies never receives a fix, and the server it held booted a different kernel than `coa` — without the machine's
// config (`.milpa/agent.json`) or its secrets — and kept that kernel for as long as the client stayed, so a plugin
// installed, promoted or disabled afterwards was invisible (a disabled one kept answering). `coa mcp` lives in
// milpa/app-runtime, boots the same kernel as `coa`, and restarts it when what defines the house changes.
//
// STDOUT is the protocol, one JSON-RPC message per line; everything a person reads goes to STDERR.
exit((new Application(\dirname(__DIR__)))->run([$argv[0], 'mcp', ...\array_slice($argv, 1)]));
