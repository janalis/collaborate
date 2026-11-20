// Importing the page's or the topics' script starts no bell: bell.js starts every bell of the page when it loads.
test('the topics and the page scripts start no bell; the bell script does', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn(() => new Promise(() => {}))
  );
  document.body.innerHTML = `
    <div data-collaborate-notifications='{"src": "/notifications"}'>
      <button class="collaborate-notifications__toggle" data-label="Notifications"></button>
      <span class="collaborate-notifications__count"></span>
      <div class="collaborate-notifications__menu">
        <div class="collaborate-notification" data-unread="true"></div>
      </div>
    </div>`;
  const badge = document.querySelector('.collaborate-notifications__count');

  await import('../../../assets/notifications/topics.js');
  await import('../../../assets/notifications/page.js');
  expect(badge.textContent).toBe('');

  await import('../../../assets/notifications/bell.js');
  expect(badge.textContent).toBe('1');
  vi.unstubAllGlobals();
});
