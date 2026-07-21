<?php

namespace Pinoox\Component\Server\Share;

enum ShareSetupLevel: string
{
    /** Ready to use — no signup or extra tools. */
    case Ready = 'ready';

    /** Binary can be downloaded automatically (cloudflared, bore). */
    case AutoInstall = 'auto_install';

    /** Missing system tool (OpenSSH, Node.js). */
    case NeedsTool = 'needs_tool';

    /** Requires account / API token (ngrok). */
    case NeedsAccount = 'needs_account';
}
