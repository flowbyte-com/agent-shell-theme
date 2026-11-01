<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Error_Codes {
    const PARSE_ERROR       = -32700;
    const INVALID_REQUEST   = -32600;
    const METHOD_NOT_FOUND  = -32601;
    const INVALID_PARAMS    = -32602;
    const INTERNAL_ERROR   = -32603;
    // Transport-specific codes (range -32000 to -32099)
    const CONNECTION_ERROR = -32000;
    const AUTH_ERROR       = -32001;
    const TIMEOUT_ERROR    = -32002;
    const RATE_LIMITED     = -32003;
}
