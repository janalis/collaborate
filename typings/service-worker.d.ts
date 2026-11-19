// The service worker scope assets/notifications/push-worker.js runs in. TypeScript's `webworker` library describes
// it, but cannot share a program with the `dom` one the other modules need: what the worker uses is declared here.

interface CollaborateWindowClient {
  readonly url: string;
  readonly focused: boolean;
  postMessage(message: unknown): void;
  focus(): Promise<CollaborateWindowClient>;
  navigate(url: string): Promise<CollaborateWindowClient | null>;
}

interface CollaborateExtendableEvent extends Event {
  waitUntil(promise: Promise<unknown>): void;
}

interface CollaboratePushEvent extends CollaborateExtendableEvent {
  readonly data: { json(): unknown } | null;
}

interface CollaborateNotificationEvent extends CollaborateExtendableEvent {
  readonly notification: Notification;
}

interface CollaborateWorkerScope {
  /** What PushController's line sets before the script: the url that reads a notification, what a bare push says. */
  collaborateNotifications?: { read?: string; title?: string; body?: string; icon?: string };
  readonly location: Location;
  readonly registration: ServiceWorkerRegistration;
  readonly clients: {
    matchAll(options: { type: 'window'; includeUncontrolled: boolean }): Promise<CollaborateWindowClient[]>;
    claim(): Promise<void>;
    openWindow(url: string): Promise<CollaborateWindowClient | null>;
  };
  fetch: typeof fetch;
  skipWaiting(): Promise<void>;
  addEventListener(type: 'install' | 'activate', listener: (event: CollaborateExtendableEvent) => void): void;
  addEventListener(type: 'push', listener: (event: CollaboratePushEvent) => void): void;
  addEventListener(type: 'notificationclick', listener: (event: CollaborateNotificationEvent) => void): void;
}
