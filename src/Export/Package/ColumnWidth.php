<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Export\Package;

enum ColumnWidth
{
    case EXTRA_SMALL;
    case SMALL;
    case DEFAULT;
    case MEDIUM;
    case LARGE;
}
