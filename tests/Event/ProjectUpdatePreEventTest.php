<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Event;

use App\Entity\Project;
use App\Event\AbstractProjectEvent;
use App\Event\ProjectUpdatePreEvent;

/**
 * @covers \App\Event\AbstractProjectEvent
 * @covers \App\Event\ProjectUpdatePreEvent
 */
class ProjectUpdatePreEventTest extends AbstractProjectEventTestCase
{
    protected function createProjectEvent(Project $project): AbstractProjectEvent
    {
        return new ProjectUpdatePreEvent($project);
    }
}
