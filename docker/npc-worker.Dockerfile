FROM mcr.microsoft.com/dotnet/sdk:10.0 AS build
WORKDIR /src
COPY src/DeskBox.NpcWorker/DeskBox.NpcWorker.csproj src/DeskBox.NpcWorker/
RUN dotnet restore src/DeskBox.NpcWorker/DeskBox.NpcWorker.csproj
COPY src/DeskBox.NpcWorker/ src/DeskBox.NpcWorker/
RUN dotnet publish src/DeskBox.NpcWorker/DeskBox.NpcWorker.csproj -c Release -o /app/publish --no-restore
FROM mcr.microsoft.com/dotnet/aspnet:10.0
WORKDIR /app
COPY --from=build /app/publish .
EXPOSE 8080
USER app
ENTRYPOINT ["dotnet", "DeskBox.NpcWorker.dll"]
