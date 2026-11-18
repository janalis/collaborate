// @ts-check
// The JSON settings the server renders into an attribute (the layer's `data-config`, the bell's settings): an
// absent or malformed one reads as none, and each module keeps its defaults.

/**
 * @param {Element | null | undefined} element
 * @param {string} attribute
 * @returns {Record<string, any>}
 */
export function settingsOf(element, attribute) {
  try {
    const settings = JSON.parse(element?.getAttribute(attribute) || '{}');
    return settings && typeof settings === 'object' && !Array.isArray(settings) ? settings : {};
  } catch {
    return {};
  }
}
