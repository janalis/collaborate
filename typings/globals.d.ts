// What the browsers the bundle supports have, and TypeScript's DOM library does not describe yet.
interface Navigator {
  /** User-Agent Client Hints: Chromium's platform, which `navigator.platform` reports less exactly. */
  readonly userAgentData?: { readonly platform?: string };
}
