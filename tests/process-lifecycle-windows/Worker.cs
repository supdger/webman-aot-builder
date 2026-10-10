using System;
using System.Diagnostics;
using System.IO;
using System.Text;
using System.Threading;

public static class OwnedWorker {
    static readonly UTF8Encoding Utf8 = new UTF8Encoding(false);
    static string Quote(string value) {
        var b = new StringBuilder("\""); int n = 0;
        foreach (char c in value) {
            if (c == '\\') { n++; continue; }
            b.Append('\\', c == '"' ? n * 2 + 1 : n); b.Append(c); n = 0;
        }
        b.Append('\\', n * 2); return b.Append('"').ToString();
    }
    static void Record(string directory, string role) {
        Directory.CreateDirectory(directory);
        using (var self = Process.GetCurrentProcess()) {
            File.WriteAllText(Path.Combine(directory, self.Id + ".json"),
                "{\"pid\":" + self.Id + ",\"created\":" + self.StartTime.ToUniversalTime().ToFileTimeUtc() +
                ",\"role\":\"" + role + "\"}", Utf8);
        }
    }
    static void Spawn(string mode, string directory, int duration) {
        var p = new ProcessStartInfo(Process.GetCurrentProcess().MainModule.FileName,
            Quote(mode) + " " + Quote(directory) + " " + duration);
        p.UseShellExecute = false;
        Process.Start(p).Dispose();
    }
    static void WaitRoles(string directory, int count) {
        var clock = Stopwatch.StartNew();
        while (Directory.GetFiles(directory, "*.json").Length < count) {
            if (clock.ElapsedMilliseconds > 5000) throw new Exception("Child readiness timed out");
            Thread.Sleep(10);
        }
    }
    public static int Main(string[] args) {
        if (args[0] == "args") {
            for (int i = 1; i < args.Length; i++) Console.WriteLine(Convert.ToBase64String(Encoding.UTF8.GetBytes(args[i])));
            Console.Error.WriteLine("stderr-probe");
            return 0;
        }
        if (args[0] == "high-exit") return unchecked((int)0xC0000005);
        if (args[0] == "hold") {
            using (var file = new FileStream(args[1], FileMode.Open, FileAccess.Read, FileShare.None)) {
                Record(args[2], "holder");
                Console.WriteLine("HOLDREADY");
                Thread.Sleep(Int32.Parse(args[3]));
            }
            return 0;
        }
        string mode = args[0], directory = args[1];
        int duration = Int32.Parse(args[2]);
        Record(directory, mode);
        if (mode == "child") {
            Spawn("grandchild", directory, duration);
            WaitRoles(directory, 3);
            Thread.Sleep(duration);
            return 0;
        }
        if (mode == "grandchild") { Thread.Sleep(duration); return 0; }
        Spawn("child", directory, duration);
        WaitRoles(directory, 3);
        Console.WriteLine("ROOTREADY");
        if (mode == "root-stays") Thread.Sleep(30000);
        return mode == "root-fails" ? 7 : 0;
    }
}
