<?php

use App\Http\Middleware\EnsureHermesMcpAccess;
use App\Mcp\Servers\HermesServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/hermes', HermesServer::class)
    ->middleware([EnsureHermesMcpAccess::class, 'throttle:60,1']);
