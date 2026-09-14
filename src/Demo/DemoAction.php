<?php
namespace App\Demo;

/**
 * Description of DemoAction
 *
 * @author Pierre
 */
enum DemoAction
{
    case EXCLUDE;
    case COPY;
    case ANONYMIZE;
    case TRANSFORM;
}