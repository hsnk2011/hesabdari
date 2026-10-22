<?php
// api/core/audit/RuleInterface.php

interface AuditRuleInterface {
    public function getId(): string;
    public function getCategory(): string;
    public function getTitle(): string;
    /** @return array[] list of issue arrays */
    public function run(): array;
}
