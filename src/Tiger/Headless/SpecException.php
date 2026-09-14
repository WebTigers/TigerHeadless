<?php
/**
 * SPDX-License-Identifier: BSD-3-Clause
 * Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
 *
 * Every problem with a spec, reported at once — a caller fixes the whole document in one round trip.
 */
class Tiger_Headless_SpecException extends InvalidArgumentException
{
    /** @var string[] */
    protected $_problems;

    public function __construct(array $problems)
    {
        $this->_problems = array_values($problems);
        parent::__construct('Invalid spec: ' . implode('; ', $this->_problems));
    }

    /** @return string[] */
    public function problems()
    {
        return $this->_problems;
    }
}
