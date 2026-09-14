<?php
namespace App\Demo;

enum DemoStrategy: string {

    case COPY = 'copy';
    case ANONYMIZE = 'anonymize';
    case EXCLUDE = 'exclude';
}
