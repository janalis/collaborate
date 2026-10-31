vi.mock('@microsoft/fetch-event-source', () => ({ fetchEventSource: vi.fn(() => new Promise(() => {})) }));

afterEach(() => {
  vi.resetModules();
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

test('re-exports the draft session lookup and the row initializer registry', async () => {
  const entry = await import('../../assets/easyadmin.js');
  const { sessionFor } = await import('../../assets/draft/session.js');
  const { addRowInitializer } = await import('../../assets/draft/hooks.js');
  expect(entry.sessionFor).toBe(sessionFor);
  expect(entry.addRowInitializer).toBe(addRowInitializer);
});

test('starts cursors and updates on the layer, through the fetch captured before any host wrapper', async () => {
  vi.stubGlobal('matchMedia', () => ({ matches: false }));
  vi.stubGlobal(
    'ResizeObserver',
    class {
      observe() {}
      disconnect() {}
    }
  );
  document.body.innerHTML =
    '<div data-collaborate-layer data-grant="grant" data-csrf="csrf" data-session-url="/session" data-event-url="/events" data-updates="true"></div>';
  const native = vi.fn(async () => new Response(null, { status: 503 }));
  vi.stubGlobal('fetch', native);
  await import('../../assets/easyadmin.js');
  // A wrapper the host installs afterwards sees none of the background traffic.
  const wrapper = vi.fn();
  vi.stubGlobal('fetch', wrapper);
  const layer = document.querySelector('[data-collaborate-layer]');
  expect(layer.dataset.initialized).toBe('true');
  await vi.waitFor(() => expect(native).toHaveBeenCalled());
  expect(native.mock.calls[0][0]).toBe('/session');
  expect(JSON.parse(native.mock.calls[0][1].body)).toMatchObject({ grant: 'grant', updates: true });
  expect(wrapper).not.toHaveBeenCalled();
  window.dispatchEvent(new Event('pagehide'));
});
