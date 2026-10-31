const splitsPair = (text, offset) =>
  offset > 0 &&
  offset < text.length &&
  text.charCodeAt(offset - 1) >= 0xd800 &&
  text.charCodeAt(offset - 1) <= 0xdbff &&
  text.charCodeAt(offset) >= 0xdc00 &&
  text.charCodeAt(offset) <= 0xdfff;

/** CKEditor offsets are UTF-16, but an operation must never serialize half a surrogate pair. */
export function textChange(before, after) {
  let prefix = 0;
  while (prefix < before.length && prefix < after.length && before[prefix] === after[prefix]) prefix++;
  if (splitsPair(before, prefix) || splitsPair(after, prefix)) --prefix;
  let suffix = 0;
  while (
    suffix < before.length - prefix &&
    suffix < after.length - prefix &&
    before.at(-suffix - 1) === after.at(-suffix - 1)
  )
    suffix++;
  if (splitsPair(before, before.length - suffix) || splitsPair(after, after.length - suffix)) --suffix;
  return { prefix, end: before.length - suffix, inserted: after.slice(prefix, after.length - suffix) };
}

/**
 * Carries a change from `before` to `after` onto `edited`, an unsaved rewrite of `before`,
 * when the two touch different parts of the text; otherwise `after` wins.
 */
export function rebaseText(edited, before, after) {
  if (edited === before) return after;
  const local = textChange(before, edited);
  const remote = textChange(before, after);
  if (remote.end <= local.prefix) return edited.slice(0, remote.prefix) + remote.inserted + edited.slice(remote.end);
  if (remote.prefix < local.end) return after;
  const shift = edited.length - before.length;
  return edited.slice(0, remote.prefix + shift) + remote.inserted + edited.slice(remote.end + shift);
}
