<?php

namespace Drupal\event_booking\Portal;

/**
 * Small collaborator for talking to the external portal user API.
 *
 * This encapsulates URL construction and HTTP calls so that
 * EventBookingApiService can focus on business rules.
 */
interface PortalUserClientInterface {

  /**
   * Returns TRUE when the portal user API is configured.
   */
  public function isConfigured(): bool;

  /**
   * Fetches the portal user details payload by identifier.
   *
   * @return array<string, mixed>|null
   *   Normalised payload array when found, or NULL when the
   *   API returns no user for the given identifier.
   *
   * @throws \Throwable
   *   Any transport/HTTP/JSON errors are allowed to bubble so
   *   the caller can map them to a generic 5xx response.
   */
  public function fetchByIdentifier(string $identifier): ?array;

}

