<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2022 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Formula\Functions\ExtGroup\WorkingTimeGroup;

use Espo\Core\Field\DateTime;
use Espo\Core\Field\DateTimeOptional;
use Espo\Core\Formula\ArgumentList;

class FindClosestWorkingTimeType extends Base
{
    public function process(ArgumentList $args)
    {
        if (count($args) < 1) {
            $this->throwTooFewArguments(1);
        }

        /** @var mixed[] $evaluatedArgs */
        $evaluatedArgs = $this->evaluate($args);

        $stringValue = $evaluatedArgs[0];

        if (!is_string($stringValue)) {
            $this->throwBadArgumentType(1, 'string');
        }

        $calendar = $this->createCalendar($evaluatedArgs);

        $dateTime = DateTimeOptional::fromString($stringValue);

        if ($dateTime->isAllDay()) {
            $dateTime = $dateTime->withTimezone($calendar->getTimezone());
        }

        $dateTime = DateTime::fromDateTime($dateTime->getDateTime());

        $result = $this->createCalendarUtility($calendar)->findClosestWorkingTime($dateTime);

        if (!$result) {
            return null;
        }

        return $result->getString();
    }
}
