using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.ServiceProcess;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Web.Script.Serialization;

internal sealed class DreamHostingMonitorService : ServiceBase
{
    private readonly RunnerConfiguration configuration;
    private readonly ManualResetEvent stopSignal = new ManualResetEvent(false);
    private readonly object processLock = new object();
    private readonly object logLock = new object();
    private Thread workerThread;
    private Process childProcess;

    private DreamHostingMonitorService(RunnerConfiguration runnerConfiguration)
    {
        configuration = runnerConfiguration;
        ServiceName = configuration.ServiceName;
        CanStop = true;
        AutoLog = false;
    }

    private static int Main(string[] args)
    {
        if (args.Length == 1 && String.Equals(args[0], "--self-check", StringComparison.OrdinalIgnoreCase))
        {
            Console.WriteLine("Service host loaded; Windows ServiceBase is available.");
            return 0;
        }

        if (args.Length == 2 && String.Equals(args[0], "--validate-config", StringComparison.OrdinalIgnoreCase))
        {
            try
            {
                RunnerConfiguration.Load(args[1]);
                Console.WriteLine("Service configuration is valid.");
                return 0;
            }
            catch
            {
                Console.Error.WriteLine("Service configuration is invalid.");
                return 2;
            }
        }

        if (args.Length != 2 || !String.Equals(args[0], "--config", StringComparison.OrdinalIgnoreCase))
        {
            return 2;
        }

        try
        {
            RunnerConfiguration configuration = RunnerConfiguration.Load(args[1]);
            ServiceBase.Run(new DreamHostingMonitorService(configuration));
            return 0;
        }
        catch
        {
            return 1;
        }
    }

    protected override void OnStart(string[] args)
    {
        stopSignal.Reset();
        workerThread = new Thread(RunProcessLoop);
        workerThread.IsBackground = true;
        workerThread.Name = configuration.ServiceName + " process runner";
        workerThread.Start();
    }

    protected override void OnStop()
    {
        stopSignal.Set();
        lock (processLock)
        {
            if (childProcess != null && !childProcess.HasExited)
            {
                try
                {
                    childProcess.Kill();
                    childProcess.WaitForExit(5000);
                }
                catch
                {
                    AppendServiceLog("error", "The child process could not be stopped cleanly.");
                }
            }
        }

        if (workerThread != null)
        {
            workerThread.Join(TimeSpan.FromSeconds(15));
        }
    }

    private void RunProcessLoop()
    {
        while (!stopSignal.WaitOne(0))
        {
            try
            {
                int exitCode = StartAndMonitorProcess();
                if (!stopSignal.WaitOne(0))
                {
                    AppendServiceLog("supervisor", "Child process exited with code " + exitCode + "; it will restart after a short delay.");
                }
            }
            catch
            {
                AppendServiceLog("supervisor", "The child process could not start; it will retry after a short delay.");
            }

            if (stopSignal.WaitOne(TimeSpan.FromSeconds(configuration.RestartDelaySeconds)))
            {
                return;
            }
        }
    }

    private int StartAndMonitorProcess()
    {
        Process child = new Process();
        child.StartInfo = new ProcessStartInfo();
        child.StartInfo.FileName = configuration.Executable;
        child.StartInfo.Arguments = configuration.Arguments;
        child.StartInfo.WorkingDirectory = configuration.WorkingDirectory;
        child.StartInfo.CreateNoWindow = true;
        child.StartInfo.UseShellExecute = false;
        child.StartInfo.RedirectStandardOutput = true;
        child.StartInfo.RedirectStandardError = true;
        child.OutputDataReceived += delegate(object sender, DataReceivedEventArgs eventArgs)
        {
            if (eventArgs.Data != null)
            {
                AppendServiceLog("stdout", eventArgs.Data);
            }
        };
        child.ErrorDataReceived += delegate(object sender, DataReceivedEventArgs eventArgs)
        {
            if (eventArgs.Data != null)
            {
                AppendServiceLog("stderr", eventArgs.Data);
            }
        };

        try
        {
            if (!child.Start())
            {
                throw new InvalidOperationException();
            }

            child.BeginOutputReadLine();
            child.BeginErrorReadLine();
            lock (processLock)
            {
                childProcess = child;
                if (stopSignal.WaitOne(0) && !child.HasExited)
                {
                    child.Kill();
                }
            }

            child.WaitForExit();
            return child.ExitCode;
        }
        finally
        {
            lock (processLock)
            {
                if (Object.ReferenceEquals(childProcess, child))
                {
                    childProcess = null;
                }
            }
            child.Dispose();
        }
    }

    private void AppendServiceLog(string stream, string message)
    {
        try
        {
            string directory = Path.GetDirectoryName(configuration.LogPath);
            Directory.CreateDirectory(directory);
            string path = configuration.LogPath;
            if (File.Exists(path) && new FileInfo(path).Length >= 5 * 1024 * 1024)
            {
                string archivePath = path + "." + DateTime.UtcNow.ToString("yyyyMMddHHmmss") + ".old";
                File.Move(path, archivePath);
            }

            string safeMessage = RedactSensitiveText(message ?? String.Empty);
            if (safeMessage.Length > 2000)
            {
                safeMessage = safeMessage.Substring(0, 2000);
            }

            string logLine = DateTime.UtcNow.ToString("o") + " [" + stream + "] " + safeMessage + Environment.NewLine;
            lock (logLock)
            {
                File.AppendAllText(path, logLine, new UTF8Encoding(false));
            }
        }
        catch
        {
            // Log failures must not prevent the child process from running.
        }
    }

    private static string RedactSensitiveText(string value)
    {
        string redacted = Regex.Replace(value, @"(?i)(bearer\s+)[A-Za-z0-9._~+/-]+=*", "$1[REDACTED]");
        redacted = Regex.Replace(
            redacted,
            @"(?i)((?:enrollment[_-]?token|credential|password|secret|api[_-]?key)\s*[:=]\s*)[^,\s;]+",
            "$1[REDACTED]"
        );
        return Regex.Replace(redacted, @"\b[a-fA-F0-9]{64}\b", "[REDACTED]");
    }

    private sealed class RunnerConfiguration
    {
        public string ServiceName;
        public string Executable;
        public string Arguments;
        public string WorkingDirectory;
        public string LogPath;
        public int RestartDelaySeconds;

        public static RunnerConfiguration Load(string path)
        {
            if (String.IsNullOrWhiteSpace(path) || !File.Exists(path))
            {
                throw new InvalidOperationException();
            }

            string json = File.ReadAllText(path, Encoding.UTF8);
            JavaScriptSerializer serializer = new JavaScriptSerializer();
            Dictionary<string, object> values = serializer.Deserialize<Dictionary<string, object>>(json);
            if (values == null)
            {
                throw new InvalidOperationException();
            }

            RunnerConfiguration configuration = new RunnerConfiguration();
            configuration.ServiceName = ReadString(values, "service_name");
            configuration.Executable = ReadString(values, "executable");
            configuration.Arguments = ReadString(values, "arguments");
            configuration.WorkingDirectory = ReadString(values, "working_directory");
            configuration.LogPath = ReadString(values, "log_path");
            configuration.RestartDelaySeconds = values.ContainsKey("restart_delay_seconds")
                ? Convert.ToInt32(values["restart_delay_seconds"])
                : 60;

            if (configuration.ServiceName.Length > 80
                || !Path.IsPathRooted(configuration.Executable)
                || !Path.IsPathRooted(configuration.WorkingDirectory)
                || !Path.IsPathRooted(configuration.LogPath)
                || !File.Exists(configuration.Executable)
                || !Directory.Exists(configuration.WorkingDirectory)
                || configuration.RestartDelaySeconds < 5
                || configuration.RestartDelaySeconds > 600)
            {
                throw new InvalidOperationException();
            }

            return configuration;
        }

        private static string ReadString(Dictionary<string, object> values, string name)
        {
            object value;
            if (!values.TryGetValue(name, out value) || value == null)
            {
                throw new InvalidOperationException();
            }

            string result = Convert.ToString(value);
            if (String.IsNullOrWhiteSpace(result))
            {
                throw new InvalidOperationException();
            }

            return result;
        }
    }
}
