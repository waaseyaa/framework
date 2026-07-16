export const richTextHtmlFixtures = {
  headingsAndParagraphs: '<h2 class="section-title">Welcome</h2><p>Opening <strong>paragraph</strong>.</p>',
  links: '<p><a href="https://example.test/path?x=1&amp;y=2" rel="nofollow external" data-track="migration">Read more</a></p>',
  lists: '<ol start="3"><li data-key="one">First</li><li>Second<ul><li>Nested</li></ul></li></ol>',
  image: '<figure data-layout="wide"><img src="/sites/default/files/hero.jpg" alt="A lake" width="1200" height="630" loading="lazy" data-focal-x="0.4"><figcaption>Lake</figcaption></figure>',
  embed: '<div class="video" data-provider="legacy"><iframe src="https://video.example.test/embed/123" title="Community video" width="560" height="315" allow="fullscreen" data-consent="media"></iframe><embed src="/media/legacy.swf" type="application/x-shockwave-flash" data-origin="migration"></div>',
  unfamiliarAttributes: '<section aria-roledescription="feature" data-builder-id="pb-42" custom-valid="kept"><p lang="oj" dir="ltr">Aaniin</p></section>',
  unsafeActiveContent: '<p>Before</p><script>globalThis.__richTextExecuted = true</script><img src="https://tracker.example.test/pixel.gif" alt=""><iframe src="https://untrusted.example.test/"></iframe><p onclick="alert(1)">After</p>',
  empty: '',
  null: null,
} as const
