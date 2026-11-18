</main>
<footer class="site">
  <div class="wrap">
    <p>
      OriDB data release <?= h(Data::release()['release']) ?>, built <?= h(Data::release()['generated']) ?>.
      That is the date the dataset was generated, not the date the site last changed.
      Data released under CC BY 4.0. Source on
      <a href="https://github.com/DNAReplicationLab/oridb-v3">GitHub</a>.
    </p>
    <p class="small">
      Built from OriDB v2 (Nieduszynski <em>et al.</em> 2007; Siow <em>et al.</em> 2012).
      This trial site is for evaluation and its URLs are not yet stable.
    </p>
  </div>
</footer>
</body>
</html>
