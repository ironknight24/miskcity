<?php

namespace Drupal\login_logout\Exception;

/**
 * Exception thrown when the JWT payload is invalid or missing required claims.
 */
class JwtPayloadException extends \Exception {}
