"""GET /admin/backups/{name}/download: raw attachment, admin only, name validated."""
import helpers as h
from helpers import api


class BackupDownload(h.ApiTestCase):
    def setUp(self):
        r = api("POST", "/admin/backups", {"name": "api_download_probe", "overwrite": True}, token=self.admin)
        self.assertOk(r, 201)
        self.size = r.data["size"]

    def test_download_is_raw_attachment(self):
        r = api("GET", "/admin/backups/api_download_probe/download", token=self.admin)
        self.assertEqual(r.status, 200)
        self.assertIn("attachment", r.header("Content-Disposition"))
        self.assertIn("api_download_probe", r.header("Content-Disposition"))
        self.assertEqual(r.header("Content-Type"), "application/octet-stream")
        self.assertEqual(len(r.text.encode()), self.size)

    def test_missing_is_404(self):
        self.assertError(api("GET", "/admin/backups/zz_missing/download", token=self.admin), 404)

    def test_bad_names_are_rejected(self):
        for bad in (".hidden", "a%20b", "..%2Fetc%2Fpasswd"):
            with self.subTest(name=bad):
                self.assertIn(api("GET", "/admin/backups/" + bad + "/download", token=self.admin).status, (404, 422))

    def test_requires_admin(self):
        self.assertEqual(api("GET", "/admin/backups/api_download_probe/download").status, 401)
        for role in ("reader", "operator", "editor"):
            with self.subTest(role=role):
                self.assertEqual(
                    api("GET", "/admin/backups/api_download_probe/download", token=self.tokens[role]).status, 403)

    def test_backups_not_served_directly(self):
        r = h.request("GET", h.BASE.replace("/api/v1", "") + "/backups/api_download_probe")
        self.assertIn(r.status, (403, 404))
