<?php

namespace Pinoox\Component\Package\Routing;

final class AppResolution
{
    /** Runtime shell app used when router resolution fails (boot only; UI from NoAppListener). */
    public const BOOTSTRAP_PACKAGE = 'com_pinoox_welcome';

    public const NOT_CONFIGURED = 'not_configured';

    public const APP_MISSING = 'app_missing';

    public const APP_DISABLED = 'app_disabled';
}
