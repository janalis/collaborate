// @ts-check
// The JSON settings the server renders into an attribute (the layer's `data-config`, the bell's settings): an
// absent or malformed one reads as none, and each module keeps its defaults.

/**
 * @param {Element | null | undefined} element
 * @param {string} attribute
 * @returns {Record<string, any>}
 */
export function settingsOf(element, attribute) {
  return parseSettings(element?.getAttribute(attribute));
}

/**
 * @param {string | null | undefined} json the attribute's value
 * @returns {Record<string, any>}
 */
export function parseSettings(json) {
  try {
    const settings = JSON.parse(json || '{}');
    return settings && typeof settings === 'object' && !Array.isArray(settings) ? settings : {};
  } catch {
    return {};
  }
}
